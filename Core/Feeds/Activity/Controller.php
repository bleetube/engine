<?php

namespace Minds\Core\Feeds\Activity;

use Minds\Common\EntityMutation;
use Minds\Core\Blogs\Blog;
use Minds\Core\Data\Locks\LockFailedException;
use Minds\Core\Di\Di;
use Minds\Core\EntitiesBuilder;
use Minds\Core\Feeds\Activity\Exceptions\CreateActivityFailedException;
use Minds\Core\Feeds\Scheduled\EntityTimeCreated;
use Minds\Core\Router\Exceptions\ForbiddenException;
use Minds\Core\Router\Exceptions\UnauthorizedException;
use Minds\Core\Router\Exceptions\UnverifiedEmailException;
use Minds\Core\Security\ACL;
use Minds\Core\Supermind\Exceptions\SupermindNotFoundException;
use Minds\Core\Supermind\Exceptions\SupermindPaymentIntentFailedException;
use Minds\Entities\Activity;
use Minds\Entities\Image;
use Minds\Entities\User;
use Minds\Entities\Video;
use Minds\Exceptions\ServerErrorException;
use Minds\Exceptions\StopEventException;
use Minds\Exceptions\UserErrorException;
use Stripe\Exception\ApiErrorException;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Diactoros\ServerRequest;

class Controller
{
    public function __construct(
        protected ?Manager $manager = null,
        protected ?EntitiesBuilder $entitiesBuilder = null,
        protected ?ACL $acl = null,
        protected ?EntityTimeCreated $entityTimeCreated = null
    ) {
        $this->manager ??= new Manager();
        $this->entitiesBuilder ??= Di::_()->get('EntitiesBuilder');
        $this->acl ??= Di::_()->get('Security\ACL');
        $this->entityTimeCreated ??= new EntityTimeCreated();
    }

    /**
     * PUT
     * @param ServerRequest $request
     * @return JsonResponse
     * @throws CreateActivityFailedException
     * @throws ServerErrorException
     * @throws UnauthorizedException
     * @throws UserErrorException
     * @throws LockFailedException
     * @throws UnverifiedEmailException
     * @throws SupermindNotFoundException
     * @throws SupermindPaymentIntentFailedException
     * @throws StopEventException
     * @throws ApiErrorException
     */
    public function createNewActivity(ServerRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->getAttribute('_user');

        $payload = $request->getParsedBody();

        $activity = new Activity();

        /**
         * NSFW and Mature
         */
        $activity->setMature(isset($payload['mature']) && !!$payload['mature']);
        $activity->setNsfw($payload['nsfw'] ?? []);

        // If a user is mature, their post should be tagged as that too
        if ($user->isMature()) {
            $activity->setMature(true);
        }

        /**
         * Access ID
         */
        if (isset($payload['access_id'])) {
            $activity->setAccessId($payload['access_id']);
        }

        /**
         * Message
         */
        if (isset($payload['message'])) {
            $activity->setMessage(rawurldecode($payload['message']));
        }

        /**
         * Reminds & Quote posts
         */
        $remind = null;
        if (isset($payload['remind_guid'])) {
            // Fetch the remind

            $remind = $this->entitiesBuilder->single($payload['remind_guid']);
            if (!(
                $remind instanceof Activity ||
                $remind instanceof Image ||
                $remind instanceof Video ||
                $remind instanceof Blog
            )) {
                // We should **NOT** allow for the reminding of non-Activity entities,
                // however this is causing client side regressions and they are being cast to activity views
                // This can be revisited once we migrate entirely away from ->entity_guid support.
                throw new UserErrorException("The post your are trying to remind or quote was not found");
            }

            // throw and error return response if acl interaction check fails.

            if (!$this->acl->interact($remind, $user)) {
                throw new UnauthorizedException();
            }
            $shouldBeQuotedPost = $payload['message'] || (
                is_array($payload['attachment_guids']) &&
                count($payload['attachment_guids'])
            );
            // $shouldBeQuotedPost = $payload['message'] || count($payload['attachment_guids']);
            $remindIntent = new RemindIntent();
            $remindIntent->setGuid($remind->getGuid())
                        ->setOwnerGuid($remind->getOwnerGuid())
                        ->setQuotedPost($shouldBeQuotedPost);

            $activity->setRemind($remindIntent);
        }

        /**
         * Wire/Paywall
         */
        if (isset($payload['wire_threshold']) && $payload['wire_threshold']) {
            // don't allow paywalling a paywalled remind
            if ($remind?->getPaywall()) {
                throw new UserErrorException("You can not monetize a remind or quote post");
            }

            $activity->setWireThreshold($payload['wire_threshold']);
        }

        /**
         * Container (ie. groups or other entity ownership)
         */
        $container = null;

        if (isset($payload['container_guid']) && $payload['container_guid']) {
            if (isset($payload['wire_threshold']) && $payload['wire_threshold']) {
                throw new UserErrorException("You can not monetize group posts");
            }

            $activity->container_guid = $payload['container_guid'];
            if ($container = $this->entitiesBuilder->single($activity->container_guid)) {
                $activity->containerObj = $container->export();
            }
            $activity->indexes = [
                "activity:container:$activity->container_guid",
                "activity:network:$activity->owner_guid"
            ];

            // Core\Events\Dispatcher::trigger('activity:container:prepare', $container->type, [
            //     'container' => $container,
            //     'activity' => $activity,
            // ]);
        }

        /**
         * Tags
         */
        if (isset($payload['tags'])) {
            $activity->setTags($payload['tags']);
        }

        /**
         * License
         */
        $activity->setLicense($payload['license'] ?? $payload['attachment_license'] ?? '');

        /**
         * Attachments
         */
        if (isset($payload['attachment_guids']) && count($payload['attachment_guids']) > 0) {

            /**
             * Build out the attachment entities
             */
            $attachmentEntities = $this->entitiesBuilder->get([ 'guid' => $payload['attachment_guids']]);

            $imageCount = count(array_filter($attachmentEntities, function ($attachmentEntity) {
                return $attachmentEntity instanceof Image;
            }));

            $videoCount = count(array_filter($attachmentEntities, function ($attachmentEntity) {
                return $attachmentEntity instanceof Video;
            }));

            // validate there is not a mix of videos and images
            if ($imageCount >= 1 && $videoCount >= 1) {
                throw new UserErrorException("You may not have both image and videos at this time");
            }

            // if videos, validate there is only 1 video
            if ($videoCount > 1) {
                throw new UserErrorException("You can only upload one video at this time");
            }

            // ensure there is a max of 4 images
            if ($imageCount > 4) {
                throw new UserErrorException("You can not upload more 4 images");
            }

            $activity->setAttachments($attachmentEntities);

            if (isset($payload['title'])) { // Only attachment posts can have titles
                $activity->setTitle($payload['title']);
            }
        }

        /**
         * Rich embeds
         */
        if (isset($payload['url']) && !$activity->hasAttachments()) {
            $activity
                ->setTitle(rawurldecode($payload['title']))
                ->setBlurb(rawurldecode($payload['description']))
                ->setURL(rawurldecode($payload['url']))
                ->setThumbnail($payload['thumbnail']);
        }

        /**
         * Scheduled posts
         */
        if (isset($payload['time_created'])) {
            $now = time();
            $this->entityTimeCreated->validate($activity, $payload['time_created'] ?? $now, $now);
        }

        /**
         * Save the activity
         */
        if (isset($payload['supermind_request'])) {
            $this->manager->addSupermindRequest($payload, $activity);
        } elseif (isset($payload['supermind_reply_guid'])) {
            $this->manager->addSupermindReply($payload, $activity);
        } elseif (!$this->manager->add($activity)) {
            throw new ServerErrorException("The post could not be saved.");
        }

        /**
         * Post save, update the access id and container id of the attachment entities, now we have a GUID
         */
        if (isset($attachmentEntities)) {
            // update the container guid of the image to be the activity guid
            foreach ($attachmentEntities as $attachmentEntity) {
                $attachmentEntity->container_guid = $activity->getGuid();
                $attachmentEntity->access_id = $activity->getGuid();
                $this->manager->patchAttachmentEntity($activity, $attachmentEntity);
            }
        }

        return new JsonResponse($activity->export());
    }

    /**
     * POST
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function updateExistingActivity(ServerRequest $request): JsonResponse
    {
        /** @var User */
        $user = $request->getAttribute('_user');

        /** @var string */
        $activityGuid = $request->getAttribute('parameters')['guid'] ?? '';

        if (!$activityGuid) {
            throw new UserErrorException('You must provide a guid');
        }

        $payload = $request->getParsedBody();

        /** @var Activity */
        $activity = $this->entitiesBuilder->single($activityGuid);

        /**
         * Validate if exists
         */
        if (!$activity) {
            throw new UserErrorException('Activity not found');
        }

        // When editing media posts, they can sometimes be non-activity entities
        // so we provide some additional field
        // TODO: Anoter possible bug is the descrepency between 'description' and 'message'
        // here we are updating message field. Propose fixing this at Object/Image level
        // vs patching on activity
        // !!!!
        // !! Inheritted from v2 API - not applicable to new entities without entity_guid !!
        // !!!!
        if (!$activity instanceof Activity) {
            $subtype = $activity->getSubtype();
            $type = $activity->getType();
            $activity = $this->manager->createFromEntity($activity);
            $activity->guid = $activityGuid; // createFromEntity makes a new entity
            $activity->subtype = $subtype;
            $activity->type = $type;
        }

        /**
         * Check we can edit
         */
        if (!$activity->canEdit()) {
            throw new ForbiddenException("Invalid permission to edit this activity post");
        }

        /**
         * We edit the mutated activity so we know what has changed
         */
        $mutatedActivity = new EntityMutation($activity);

        /**
         * NSFW and Mature
         */
        $mutatedActivity->setMature(isset($payload['mature']) && !!$payload['mature']);
        $mutatedActivity->setNsfw($payload['nsfw'] ?? []);

        // If a user is mature, their post should be tagged as that too
        if ($user->isMature()) {
            $mutatedActivity->setMature(true);
        }

        /**
         * Access ID
         */
        if (isset($payload['access_id'])) {
            $mutatedActivity->setAccessId($payload['access_id']);
        }

        /**
         * Message
         */
        if (isset($payload['message'])) {
            $mutatedActivity->setMessage(rawurldecode($payload['message']));
        }

        /**
         * Time Created
         */
        if (isset($payload['time_created'])) {
            $now = time();
            $this->entityTimeCreated->validate($mutatedActivity, $payload['time_created'] ?? $now, $now);
        }

        /**
         * Title
         */
        if (isset($payload['title']) && $activity->hasAttachments()) {
            $mutatedActivity->setTitle($payload['title']);
        }

        /**
         * Tags
         */
        if (isset($payload['tags'])) {
            $mutatedActivity->setTags($payload['tags']);
        }

        /**
         * License
         */
        $mutatedActivity->setLicense($payload['license'] ?? $payload['attachment_license'] ?? '');

        /**
         * Rich embeds
         */
        if (isset($payload['url']) && !$activity->hasAttachments()) {
            $mutatedActivity
                ->setTitle(rawurldecode($payload['title']))
                ->setBlurb(rawurldecode($payload['description']))
                ->setURL(rawurldecode($payload['url']))
                ->setThumbnail($payload['thumbnail']);
        }

        /**
         * Save the activity
         */
        if (!$this->manager->update($mutatedActivity)) {
            throw new ServerErrorException("The post could not be saved.");
        }

        /** @var Activity */
        $originalActivity = $mutatedActivity->getMutatedEntity();

        return new JsonResponse($originalActivity->export());
    }

    /**
     * Delete entity enpoint
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function delete(ServerRequest $request): JsonResponse
    {
        $parameters = $request->getAttribute('parameters');
        if (!($parameters['urn'] ?? null)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => ':urn not provided'
            ]);
        }

        /** @var string */
        $urn = $parameters['urn'];

        $entity = $this->manager->getByUrn($urn);

        if (!$entity) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'The post does not appear to exist',
            ]);
        }

        if ($entity->canEdit()) {
            if ($this->manager->delete($entity)) {
                return new JsonResponse([
                    'status' => 'success',
                ]);
            }
        }

        return new JsonResponse([
            'status' => 'error',
            'message' => 'There was an unknown error deleting this post',
        ]);
    }

    /**
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function getRemindList(ServerRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }

    /**
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function getQuoteList(ServerRequest $request): JsonResponse
    {
        return new JsonResponse([]);
    }
}
