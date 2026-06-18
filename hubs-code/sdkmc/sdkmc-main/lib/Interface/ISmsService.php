<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Interface;

interface ISmsService {
    /**
     * @return string Id of the message being sent
     */
    public function sendSms(string $recipient, string $message): string;

    /**
     * @return 'created'|'sent'|'delivered'|'failed'
     */
    public function getStatus(string $id): string;
}
