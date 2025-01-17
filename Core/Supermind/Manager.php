<?php

declare(strict_types=1);

namespace Minds\Core\Supermind;

use Exception;
use Iterator;
use Minds\Common\Repository\Response;
use Minds\Core\Blockchain\Wallets\OffChain\Exceptions\OffchainWalletInsufficientFundsException;
use Minds\Core\Data\Locks\KeyNotSetupException;
use Minds\Core\Data\Locks\LockFailedException;
use Minds\Core\Di\Di;
use Minds\Core\EntitiesBuilder;
use Minds\Core\Payments\Stripe\Exceptions\StripeTransferFailedException;
use Minds\Core\Router\Exceptions\ForbiddenException;
use Minds\Core\Router\Exceptions\UnverifiedEmailException;
use Minds\Core\Security\ACL;
use Minds\Core\Supermind\Delegates\EventsDelegate;
use Minds\Core\Supermind\Delegates\TwitterEventsDelegate;
use Minds\Core\Supermind\Exceptions\SupermindNotFoundException;
use Minds\Core\Supermind\Exceptions\SupermindOffchainPaymentFailedException;
use Minds\Core\Supermind\Exceptions\SupermindPaymentIntentCaptureFailedException;
use Minds\Core\Supermind\Exceptions\SupermindPaymentIntentFailedException;
use Minds\Core\Supermind\Exceptions\SupermindRequestAcceptCompletionException;
use Minds\Core\Supermind\Exceptions\SupermindRequestCreationCompletionException;
use Minds\Core\Supermind\Exceptions\SupermindRequestDeleteException;
use Minds\Core\Supermind\Exceptions\SupermindRequestExpiredException;
use Minds\Core\Supermind\Exceptions\SupermindRequestIncorrectStatusException;
use Minds\Core\Supermind\Exceptions\SupermindRequestStatusUpdateException;
use Minds\Core\Supermind\Exceptions\SupermindUnauthorizedSenderException;
use Minds\Core\Supermind\Models\SupermindRequest;
use Minds\Core\Supermind\Payments\SupermindPaymentProcessor;
use Minds\Core\Twitter\Exceptions\TwitterDetailsNotFoundException;
use Minds\Entities\User;
use Minds\Exceptions\ServerErrorException;
use Minds\Exceptions\StopEventException;
use Minds\Exceptions\UserCashSetupException;
use Minds\Exceptions\UserErrorException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;

/**
 *
 */
class Manager
{
    private ?User $user = null;

    public function __construct(
        private ?Repository $repository = null,
        private ?SupermindPaymentProcessor $paymentProcessor = null,
        private ?EventsDelegate $eventsDelegate = null,
        private ?ACL $acl = null,
        private ?EntitiesBuilder $entitiesBuilder = null,
        private ?TwitterEventsDelegate $twitterEventsDelegate = null
    ) {
        $this->repository ??= Di::_()->get("Supermind\Repository");
        $this->paymentProcessor ??= new SupermindPaymentProcessor();
        $this->eventsDelegate ??= new EventsDelegate();
        $this->acl ??= Di::_()->get('Security\ACL');
        $this->entitiesBuilder ??= Di::_()->get('EntitiesBuilder');
        $this->twitterEventsDelegate ??= new TwitterEventsDelegate();
    }

    /**
     * @param User $user
     * @return $this
     */
    public function setUser(User $user): self
    {
        $this->user = $user;
        $this->paymentProcessor->setUser($user);
        return $this;
    }

    /**
     * @param SupermindRequest $supermindRequest
     * @param string|null $paymentMethodId
     * @return bool
     * @throws ApiErrorException
     * @throws ForbiddenException
     * @throws KeyNotSetupException
     * @throws LockFailedException
     * @throws OffchainWalletInsufficientFundsException
     * @throws ServerErrorException
     * @throws SupermindOffchainPaymentFailedException
     * @throws SupermindPaymentIntentFailedException
     * @throws UnverifiedEmailException
     */
    public function addSupermindRequest(SupermindRequest $supermindRequest, ?string $paymentMethodId = null): bool
    {
        if (
            !$this->acl->interact(
                $this->buildUser(
                    $supermindRequest->getReceiverGuid()
                ),
                $this->user
            )
        ) {
            throw new ForbiddenException();
        }

        $this->repository->beginTransaction();

        try {
            if ($supermindRequest->getPaymentMethod() == SupermindRequestPaymentMethod::CASH) {
                $paymentIntentId = $this->setupCashPayment($paymentMethodId, $supermindRequest);
                $supermindRequest->setPaymentTxID($paymentIntentId);
            } else {
                $this->paymentProcessor->setupOffchainPayment($supermindRequest);
            }

            $isRequestAdded = $this->repository->addSupermindRequest($supermindRequest);

            if (!$isRequestAdded) {
                if ($supermindRequest->getPaymentMethod() == SupermindRequestPaymentMethod::CASH) {
                    $this->paymentProcessor->cancelPaymentIntent($supermindRequest->getPaymentTxID());
                } else {
                    $this->paymentProcessor->refundOffchainPayment($supermindRequest);
                }

                $this->repository->rollbackTransaction();
            }
        } catch (CardException $e) {
            throw new SupermindPaymentIntentFailedException(message: $e->getMessage());
        } catch (ApiErrorException $e) {
            $receiver = $this->buildUser($supermindRequest->getReceiverGuid());
            throw new SupermindPaymentIntentFailedException(message: "@" . $receiver->getUsername() . " is unable to receive payments at this time");
        } catch (OffchainWalletInsufficientFundsException $e) {
            throw new SupermindOffchainPaymentFailedException(message: $e->getMessage());
        } catch (Exception $e) {
            $this->repository->rollbackTransaction();
            throw $e;
        }

        $this->repository->commitTransaction();
        return true;
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws ApiErrorException
     * @throws ForbiddenException
     * @throws LockFailedException
     * @throws ServerErrorException
     * @throws StopEventException
     * @throws SupermindNotFoundException
     * @throws SupermindPaymentIntentCaptureFailedException
     * @throws SupermindRequestExpiredException
     * @throws SupermindRequestIncorrectStatusException
     * @throws UnverifiedEmailException
     * @throws UserCashSetupException
     * @throws UserErrorException
     */
    public function acceptSupermindRequest(string $supermindRequestId): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        if (is_null($supermindRequest)) {
            throw new SupermindNotFoundException();
        }

        if ($supermindRequest->getStatus() !== SupermindRequestStatus::CREATED) {
            throw new SupermindRequestIncorrectStatusException();
        }

        if ($supermindRequest->getPaymentMethod() === SupermindRequestPaymentMethod::CASH && !isset($this->user->getMerchant()['id'])) {
            throw new UserCashSetupException();
        }

        if ($supermindRequest->isExpired()) {
            $this->expireSupermindRequest($supermindRequestId);
            throw new SupermindRequestExpiredException();
        }

        if (!$this->acl->write($supermindRequest, $this->user, ['isReply' => true])) {
            throw new ForbiddenException();
        }

        $isTransferFailed = false;
        if ($supermindRequest->getPaymentMethod() === SupermindRequestPaymentMethod::CASH) {
            try {
                $isPaymentSuccessful = $this->paymentProcessor->capturePaymentIntent($supermindRequest->getPaymentTxID());
            } catch (StripeTransferFailedException $e) {
                $isPaymentSuccessful = false;
                $isTransferFailed = true;
            }
        } else {
            $isPaymentSuccessful = $this->paymentProcessor->creditOffchainPayment($supermindRequest);
        }

        if ($isTransferFailed) {
            $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::TRANSFER_FAILED, $supermindRequestId);
        } elseif (!$isPaymentSuccessful) {
            $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::FAILED_PAYMENT, $supermindRequestId);
            throw new SupermindPaymentIntentCaptureFailedException();
        } else {
            $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::ACCEPTED, $supermindRequestId);
        }

        return true;
    }

    /**
     * @param string $supermindRequestId
     * @param int $targetStatus
     * @return bool
     * @throws SupermindRequestStatusUpdateException
     */
    public function updateSupermindRequestStatus(string $supermindRequestId, int $targetStatus): bool
    {
        return $this->repository->updateSupermindRequestStatus($targetStatus, $supermindRequestId) ? true : throw new SupermindRequestStatusUpdateException();
    }

    /**
     * @param string $paymentMethodId
     * @param SupermindRequest $supermindRequest
     * @return string
     * @throws SupermindPaymentIntentFailedException
     * @throws Exception
     */
    private function setupCashPayment(string $paymentMethodId, SupermindRequest $supermindRequest): string
    {
        try {
            $paymentIntentId = $this->paymentProcessor->setupSupermindStripePayment($paymentMethodId, $supermindRequest);

            if (!$paymentIntentId) {
                $this->repository->rollbackTransaction();
                throw new SupermindPaymentIntentFailedException();
            }

            return $paymentIntentId;
        } catch (Exception $e) {
            $this->repository->rollbackTransaction();
            throw $e;
        }
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws ApiErrorException
     * @throws KeyNotSetupException
     * @throws LockFailedException
     * @throws OffchainWalletInsufficientFundsException
     * @throws SupermindNotFoundException
     * @throws SupermindRequestExpiredException
     * @throws SupermindRequestIncorrectStatusException
     * @throws SupermindUnauthorizedSenderException
     */
    public function revokeSupermindRequest(string $supermindRequestId): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        if (is_null($supermindRequest)) {
            throw new SupermindNotFoundException();
        }

        if ($supermindRequest->getStatus() !== SupermindRequestStatus::CREATED) {
            throw new SupermindRequestIncorrectStatusException();
        }

        if ($supermindRequest->isExpired()) {
            $this->expireSupermindRequest($supermindRequestId);
            throw new SupermindRequestExpiredException();
        }

        if ($this->user->isAdmin() || $supermindRequest->getSenderGuid() !== $this->user->getGuid()) {
            throw new SupermindUnauthorizedSenderException();
        }

        $this->reimburseSupermindPayment($supermindRequest);

        $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::REVOKED, $supermindRequestId);

        return true;
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws ApiErrorException
     * @throws ForbiddenException
     * @throws LockFailedException
     * @throws StopEventException
     * @throws SupermindNotFoundException
     * @throws SupermindRequestExpiredException
     * @throws SupermindRequestIncorrectStatusException
     * @throws SupermindUnauthorizedSenderException
     * @throws UnverifiedEmailException
     */
    public function rejectSupermindRequest(string $supermindRequestId): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        if (is_null($supermindRequest)) {
            throw new SupermindNotFoundException();
        }

        if ($supermindRequest->getStatus() !== SupermindRequestStatus::CREATED) {
            throw new SupermindRequestIncorrectStatusException();
        }

        if ($supermindRequest->isExpired()) {
            $this->expireSupermindRequest($supermindRequestId);
            throw new SupermindRequestExpiredException();
        }

        if ($supermindRequest->getReceiverGuid() !== $this->user->getGuid()) {
            throw new SupermindUnauthorizedSenderException();
        }

        if (!$this->acl->write($supermindRequest, $this->user, ['isReply' => true])) {
            throw new ForbiddenException();
        }

        $this->reimburseSupermindPayment($supermindRequest);

        $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::REJECTED, $supermindRequestId);

        $this->eventsDelegate->onRejectSupermindRequest($supermindRequest);

        return true;
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws ApiErrorException
     * @throws LockFailedException
     * @throws SupermindNotFoundException
     */
    private function expireSupermindRequest(string $supermindRequestId): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        if (is_null($supermindRequest)) {
            throw new SupermindNotFoundException();
        }

        return $this->expireSupermindRequestFromDetails($supermindRequest);
    }

    /**
     * @param SupermindRequest $supermindRequest
     * @return bool
     * @throws ApiErrorException
     * @throws LockFailedException
     */
    private function expireSupermindRequestFromDetails(SupermindRequest $supermindRequest): bool
    {
        $this->reimburseSupermindPayment($supermindRequest);

        $expired = $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::EXPIRED, $supermindRequest->getGuid());

        $this->eventsDelegate->onExpireSupermindRequest($supermindRequest);

        return $expired;
    }

    /**
     * @param SupermindRequest $request
     * @return void
     * @throws ApiErrorException
     * @throws LockFailedException
     * @throws OffchainWalletInsufficientFundsException
     * @throws KeyNotSetupException
     */
    public function reimburseSupermindPayment(SupermindRequest $request): void
    {
        if ($request->getPaymentMethod() === SupermindRequestPaymentMethod::CASH) {
            $this->paymentProcessor->cancelPaymentIntent($request->getPaymentTxID());
        } else {
            $transactionID = $this->paymentProcessor->refundOffchainPayment($request);
            $this->repository->saveSupermindRefundTransaction($request->getGuid(), $transactionID);
        }
    }

    /**
     * @param string $supermindRequestId
     * @param int $activityGuid
     * @return bool
     * @throws SupermindRequestCreationCompletionException
     */
    public function completeSupermindRequestCreation(string $supermindRequestId, int $activityGuid): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        $isSuccessful = $this->repository->updateSupermindRequestActivityGuid($supermindRequestId, $activityGuid);

        if ($isSuccessful) {
            $supermindRequest->setActivityGuid((string) $activityGuid);
            $supermindRequest->setEntity($this->entitiesBuilder->single($supermindRequest->getActivityGuid()));
            $supermindRequest->setReceiverEntity($this->entitiesBuilder->single($supermindRequest->getReceiverGuid()));
            $this->eventsDelegate->onCompleteSupermindRequestCreation($supermindRequest);
        }

        return $isSuccessful
            ? true
            : throw new SupermindRequestCreationCompletionException();
    }

    /**
     * @param string $supermindRequestId
     * @param int $replyActivityGuid
     * @return bool
     * @throws SupermindRequestAcceptCompletionException
     * @throws TwitterDetailsNotFoundException
     */
    public function completeAcceptSupermindRequest(string $supermindRequestId, int $replyActivityGuid): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        $isSuccessful = $this->repository->updateSupermindRequestReplyActivityGuid($supermindRequestId, $replyActivityGuid);

        if ($isSuccessful) {
            $supermindRequest->setReplyActivityGuid((string) $replyActivityGuid);
            $supermindRequest->setEntity($this->entitiesBuilder->single($supermindRequest->getActivityGuid()));
            $supermindRequest->setReceiverEntity($this->entitiesBuilder->single($supermindRequest->getReceiverGuid()));
            $this->eventsDelegate->onAcceptSupermindRequest($supermindRequest);
            $this->twitterEventsDelegate->onAcceptSupermindOffer($supermindRequest);
        }

        return $isSuccessful
            ? true
            : throw new SupermindRequestAcceptCompletionException();
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws SupermindRequestDeleteException
     */
    public function deleteSupermindRequest(string $supermindRequestId): bool
    {
        $isSuccessful = $this->repository->deleteSupermindRequest($supermindRequestId);

        return $isSuccessful
            ? true
            : throw new SupermindRequestDeleteException();
    }

    /**
     * @param int $offset
     * @param int $limit
     * @param int|null $status
     * @return Response
     */
    public function getReceivedRequests(int $offset, int $limit, ?int $status): Response
    {
        $requests = [];
        foreach ($this->repository->getReceivedRequests(
            receiverGuid: (string) $this->user->getGuid(),
            offset: $offset,
            limit: $limit,
            status: $status
        ) as $supermindRequest) {
            $requests[] = $supermindRequest;
        }

        return new Response($requests);
    }

    /**
     * Count received requests for instance user.
     * @param integer|null $status - status to count for (null will return all).
     * @return integer returns count of received requests.
     */
    public function countReceivedRequests(?int $status = null): int
    {
        return $this->repository->countReceivedRequests(
            receiverGuid: (string) $this->user->getGuid(),
            status: $status
        ) ?? 0;
    }

    /**
     * @param int $offset
     * @param int $limit
     * @param int|null $status
     * @return Response
     */
    public function getSentRequests(int $offset, int $limit, ?int $status): Response
    {
        $requests = [];
        foreach ($this->repository->getSentRequests(
            senderGuid: (string) $this->user->getGuid(),
            offset: $offset,
            limit: $limit,
            status: $status
        ) as $supermindRequest) {
            $requests[] = $supermindRequest;
        }

        return new Response($requests);
    }

    /**
     * Count sent requests for instance user.
     * @param integer|null $status - status to count for (null will return all).
     * @return integer returns count of sent requests.
     */
    public function countSentRequests(?int $status = null): int
    {
        return $this->repository->countSentRequests(
            senderGuid: (string) $this->user->getGuid(),
            status: $status
        ) ?? 0;
    }

    /**
     * @param string $supermindRequestId
     * @return SupermindRequest
     * @throws ForbiddenException
     * @throws SupermindNotFoundException
     */
    public function getRequest(string $supermindRequestId): SupermindRequest
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId) ?? throw new SupermindNotFoundException();

        if (!$this->acl->read($supermindRequest, $this->user)) {
            throw new ForbiddenException("Unable to view this Supermind - Are you logged into the correct account?");
        }

        $supermindRequest->setEntity($this->entitiesBuilder->single($supermindRequest->getActivityGuid()));
        $supermindRequest->setReceiverEntity($this->entitiesBuilder->single($supermindRequest->getReceiverGuid()));

        return $supermindRequest;
    }

    public function getSupermindRequestsFromIds(array $supermindRequestIDs): Iterator
    {
        foreach ($this->repository->getRequestsFromIds($supermindRequestIDs) as $supermindRequest) {
            yield $supermindRequest;
        }
    }

    /**
     * @return bool
     * @throws ForbiddenException
     * @throws Exception
     */
    public function expireRequests(): bool
    {
        if (php_sapi_name() !== "cli") {
            throw new ForbiddenException();
        }

        $this->repository->beginTransaction();

        try {
            $expiredSupermindRequests = $this->repository->expireSupermindRequests(SupermindRequest::SUPERMIND_EXPIRY_THRESHOLD);
        } catch (Exception $e) {
            $this->repository->rollbackTransaction();
            throw $e;
        }

        if (count($expiredSupermindRequests) === 0) {
            return true;
        }

        foreach ($this->getSupermindRequestsFromIds($expiredSupermindRequests) as $supermindRequest) {
            try {
                $this->eventsDelegate->onExpireSupermindRequest($supermindRequest);

                if ($supermindRequest->getPaymentMethod() === SupermindRequestPaymentMethod::OFFCHAIN_TOKEN) {
                    $transactionId = $this->paymentProcessor->refundOffchainPayment($supermindRequest);
                    $this->repository->saveSupermindRefundTransaction($supermindRequest->getGuid(), $transactionId);
                }
            } catch (Exception $e) {
                $this->repository->rollbackTransaction();
                throw $e;
            }
        }

        $this->repository->commitTransaction();
        return true;
    }

    /**
     * @param string $userGuid
     * @return User
     * @throws ServerErrorException
     */
    private function buildUser(string $userGuid): User
    {
        return $this->entitiesBuilder->single($userGuid) ?? throw new ServerErrorException();
    }

    /**
     * @param int $status
     * @return Iterator
     */
    public function getSupermindRequestsByStatus(int $status): Iterator
    {
        foreach ($this->repository->getRequestsByStatus($status) as $supermindRequest) {
            yield $supermindRequest;
        }
    }

    public function isSupermindRequestRefunded(string $supermindRequestId): bool
    {
        return (bool) $this->repository->getSupermindRefundTransactionId($supermindRequestId);
    }
}
