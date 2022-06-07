<?php

namespace Minds\Core\Notifications\Push\TopPost;

use Minds\Core\Di\Di;
use Minds\Core\Feeds\UnseenTopFeed\Manager as UnseenTopFeedManager;
use Minds\Core\Notifications\Push\System\Manager as PushManager;
use Minds\Core\Notifications\Push\DeviceSubscriptions\DeviceSubscription;
use Minds\Core\Notifications\Push\System\Builders\TopPostPushNotificationBuilder;
use Minds\Exceptions\ServerErrorException;

/**
 * Manager for top post push notification - a notification containing
 * information from a single post from the users unseen top feed.
 */
class Manager
{
    /**
     * Constructor
     * @param ?UnseenTopFeedManager $unseenTopFeedManager - used to get an unseen post.
     * @param ?PushManager $pushManager - used to send the notification.
     * @param ?TopPostPushNotificationBuilder $notificationBuilder - notification builder class.
     */
    public function __construct(
        private ?UnseenTopFeedManager $unseenTopFeedManager = null,
        private ?PushManager $pushManager = null,
        private ?TopPostPushNotificationBuilder $notificationBuilder = null
    ) {
        $this->unseenTopFeedManager ??= Di::_()->get('Feeds\UnseenTopFeed\Manager');
        $this->pushManager ??= Di::_()->get('Notifications\Push\System\Manager');
        $this->notificationBuilder ??= new TopPostPushNotificationBuilder();
    }

    /**
     * Send a single notification to a given device subscription.
     * @param DeviceSubscription $deviceSubscription - device subscription to send to.
     * @return void
     */
    public function sendSingle(DeviceSubscription $deviceSubscription): void
    {
        $entityResponse = $this->unseenTopFeedManager->getList(
            userGuid: $deviceSubscription->getUserGuid(),
            limit: 1
        );

        if (!$entityResponse->first() || !$entityResponse->first()->getEntity()) {
            throw new ServerErrorException('Unable to find post for this user');
        }

        $pushNotification = $this->notificationBuilder
            ->withEntity($entityResponse->first()->getEntity())
            ->build()
            ->setDeviceSubscription($deviceSubscription);

        $this->pushManager->sendNotification($pushNotification);
    }
}