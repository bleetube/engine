<?php

/**
 * Blockchain Peer Boost Events
 *
 * @author emi
 */

namespace Minds\Core\Blockchain\Events;

use Minds\Core\Blockchain\Transactions\Repository;
use Minds\Core\Blockchain\Transactions\Transaction;
use Minds\Core\Boost\V3\Enums\BoostStatus;
use Minds\Core\Boost\V3\Manager as BoostManagerV3;
use Minds\Core\Data;
use Minds\Core\Di\Di;

class BoostEvent implements BlockchainEventInterface
{
    public static $eventsMap = [
        '0x68170a430a4e2c3743702c7f839f5230244aca61ed306ec622a5f393f9559040' => 'boostSent',
        '0xd7ccb5dc8647fd89286a201b04b5e65fb7b5e281603e972695fd35f52bbd244b' => 'boostAccepted',
        '0xc43f9053be9f0ee374d3f8eb929d2e0aa990d33a7d4a51423cb715228d39ab89' => 'boostRejected',
        '0x0b869ea800008714ae430dc6c4e12a2c880d50fb92937d51a4b223af34040971' => 'boostRevoked',
        'blockchain:fail' => 'boostFail',
    ];

    /** @var Repository $txRepository */
    protected $txRepository;

    /** @var \Minds\Core\Boost\Repository $boostRepository */
    protected $boostRepository;
    
    /** @var Config $config */
    protected $config;

    public function __construct(
        $txRepository = null,
        $boostRepository = null,
        private ?BoostManagerV3 $boostManagerV3 = null,
        $config = null
    ) {
        $this->txRepository = $txRepository ?: Di::_()->get('Blockchain\Transactions\Repository');
        $this->boostRepository = $boostRepository ?: Di::_()->get('Boost\Repository');
        $this->boostManagerV3 ??= Di::_()->get(BoostManagerV3::class);
        $this->config = $config ?: Di::_()->get('Config');
    }

    /**
     * @return array
     */
    public function getTopics()
    {
        return array_keys(static::$eventsMap);
    }

    /**
     * @param $topic
     * @param array $log
     * @throws \Exception
     */
    public function event($topic, array $log, $transaction)
    {
        $method = static::$eventsMap[$topic];

        if ($log['address'] != $this->config->get('blockchain')['contracts']['boost']['contract_address']) {
            throw new \Exception('Event does not match address');
        }

        if (method_exists($this, $method)) {
            $this->{$method}($log, $transaction);
        } else {
            throw new \Exception('Method not found');
        }
    }

    public function boostFail($log, $transaction)
    {
        if ($transaction->getContract() !== 'boost') {
            throw new \Exception("Failed but not a boost");
            return;
        }

        $boostGuid = $transaction->getData()['guid'];
        if ($boostV3 = $this->boostManagerV3->getBoostByGuid($boostGuid)) {
            if ($boostV3->getStatus() !== BoostStatus::PENDING_ONCHAIN_CONFIRMATION) {
                throw new \Exception("Boost with guid {$boostGuid} is not pending onchain confirmation. Status: " . $boostV3->getStatus());
            }
            $this->boostManagerV3->updateStatus($boostGuid, BoostStatus::FAILED);
            return;
        }

        $boost = $this->boostRepository
            ->getEntity($transaction->getData()['handler'], $boostGuid);

        $tx = (string) $transaction->getTx();

        if (!$boost) {
            throw new \Exception("No boost with hash {$tx}");
        }

        if ($boost->getState() != 'pending') {
            throw new \Exception("Boost with hash {$tx} already processed. State: " . $boost->getState());
        }

        $transaction->setFailed(true);

        $this->txRepository->update($transaction, [ 'failed' ]);

        $boost->setState('failed')
            ->save();
    }

    public function boostSent($log, $transaction)
    {
        $this->resolve($transaction);
    }

    /**
     * @param Transaction $transaction
     */
    private function resolve($transaction)
    {
        $boostGuid = $transaction->getData()['guid'];
        if ($boostV3 = $this->boostManagerV3->getBoostByGuid($boostGuid)) {
            if ($boostV3->getStatus() !== BoostStatus::PENDING_ONCHAIN_CONFIRMATION) {
                throw new \Exception("Boost with guid {$boostGuid} is not pending onchain confirmation. Status: " . $boostV3->getStatus());
            }
            $this->boostManagerV3->updateStatus($boostGuid, BoostStatus::PENDING);
            return;
        }

        $boost = $this->boostRepository->getEntity($transaction->getData()['handler'], $boostGuid);

        $tx = (string) $transaction->getTx();

        if (!$boost) {
            throw new \Exception("No boost with hash {$tx}");
        }

        if ($boost->getState() != 'pending') {
            throw new \Exception("Boost with hash {$tx} already processed. State: " . $boost->getState());
        }

        $boost->setState('created')
            ->save();
        echo "{$boost->getGuid()} now marked completed";
    }

    public function boostAccepted($log)
    {
    }

    public function boostRejected($log)
    {
    }

    public function boostRevoked($log)
    {
    }
}
