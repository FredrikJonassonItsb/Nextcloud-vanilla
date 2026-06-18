<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\BackgroundJob;

use OCA\Talk\Manager;
use OCA\Talk\Room;
use OCA\Talk\Service\RoomService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Background job that deletes inactive Talk rooms.
 * Runs hourly when retention is configured via deleteTalkAfterDays.
 */
class DeleteInactiveTalkRoomBackgroundJob extends SignaledJob {
    public function __construct(
        ITimeFactory $time,
        IAppConfig $appConfig,
        private Manager $manager,
        private RoomService $roomService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time, $appConfig);

        $this->setInterval(60 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function doWork(mixed $argument): void {
        $retention = $this->appConfig->getAppValueInt('deleteTalkAfterDays', 0);
        if ($retention <= 0) {
            return;
        }

        $now = $this->time->getTime();
        $minLastActivity = $now - $retention * 24 * 3600;

        $inactiveRooms = array_merge(
            $this->manager->getExpiringRoomsForObjectType('room', $minLastActivity),
            $this->manager->getExpiringRoomsForObjectType('', $minLastActivity)
        );

        $this->logger->debug('Candidate rooms for cleanup', [
            'count' => count($inactiveRooms),
            'minLastActivity' => $minLastActivity,
        ]);

        if ($inactiveRooms === []) {
            return;
        }

        $excludedObjectTypes = [
            Room::OBJECT_TYPE_EVENT,
            Room::OBJECT_TYPE_INSTANT_MEETING,
            Room::OBJECT_TYPE_PHONE_PERSIST,
            Room::OBJECT_TYPE_PHONE_TEMPORARY,
            Room::OBJECT_TYPE_NOTE_TO_SELF,
        ];
        $deleted = 0;
        foreach ($inactiveRooms as $room) {
            // Skip permanent rooms by description tag
            if (str_contains($room->getDescription(), '[permanent]')) {
                continue;
            }

            // Extra safety by room TYPE as well
            $type = $room->getType();
            if (in_array($type, [
                Room::TYPE_NOTE_TO_SELF,
                Room::TYPE_CHANGELOG,
                Room::TYPE_ONE_TO_ONE,
                Room::TYPE_ONE_TO_ONE_FORMER,
            ], true)) {
                continue;
            }

            // Skip rooms whose objectType is managed by spreed retention functionality or we decided to not delete them
            $objType = $room->getObjectType();
            if ($objType !== '' && in_array($objType, $excludedObjectTypes, true)) {
                continue;
            }

            try {
                $this->roomService->deleteRoom($room);
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->error('Talk cleanup: failed to delete room', [
                    'app' => 'sdkmc',
                    'roomId' => $room->getId(),
                    'token'  => $room->getToken(),
                    'exception' => $e,
                ]);
            }
        }

        $this->logger->info(
            "Talk cleanup removed {$deleted} room(s) inactive >= {$retention} day(s).",
            ['app' => 'sdkmc']
        );
    }
}
