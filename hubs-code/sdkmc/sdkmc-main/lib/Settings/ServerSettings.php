<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Settings;

class ServerSettings {
    /** @var Array<string, array{'secret': bool, 'lazy': bool, 'type': 'string'|'int'|'float'|'bool'|'array'}> */
    public static array $availableSettings = [
        'sdkmcmwSecretPassword' => ['secret' => true, 'lazy' => false, 'type' => 'string'],
        'smtpHost' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'smtpPort' => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'smtpInboundHost' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'smtpInboundPort' => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'imapHost' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'imapPort' => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'loginProvider' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'loa3Auth' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'debugLogin' => ['secret' => false, 'lazy' => false, 'type' => 'bool'],
        'secureMailFromEmail' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'secureMailMessage' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'secureMailSubject' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'orgSecureMailMessage' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'orgSecureMailSubject' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'securemailUseCustomSmtp' => ['secret' => false, 'lazy' => false, 'type' => 'bool'],
        'securemailSmtpHost' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'securemailSmtpPort' => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'securemailSmtpUsername' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'securemailSmtpPassword' => ['secret' => true, 'lazy' => false, 'type' => 'string'],
        'securemailSmtpSecure' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'enforcePersonalSecuremail' => ['secret' => false, 'lazy' => false, 'type' => 'bool'],
        'organizationExtension' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'addressBookBaseUrl' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'addressBookUpdateFrequency' => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'loa3Tag' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'clientId' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'clientSecret' => ['secret' => true, 'lazy' => false, 'type' => 'string'],
        'authorizeUrl' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'tokenUrl' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'redirectUrl' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'scope' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'ssnClaim' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'firstNameClaim' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'lastNameClaim' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'smsGateway' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'smsGatewayUsername' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'smsGatewayPassword' => ['secret' => true, 'lazy' => false, 'type' => 'string'],
        'smsGatewaySender' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'smsMessageContent' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'loa3Enabled' => ['secret' => false, 'lazy' => false, 'type' => 'bool'],
        'autoLogoutSeconds' => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'deleteTalkAfterDays' => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'mailRetentionDefault'   => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'mailRetentionInbox'     => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'mailRetentionSent'      => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'mailRetentionArchive'   => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'mailRetentionTrash'     => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'mailRetentionDraft'     => ['secret' => false, 'lazy' => false, 'type' => 'int'],
        'eventInvitationOrganizer' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'eventInvitationSubject' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'eventInvitationBody' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
        'secureMeetingsEnabled' => ['secret' => false, 'lazy' => false, 'type' => 'bool'],
        'hideDefaultLoginLink' => ['secret' => false, 'lazy' => false, 'type' => 'bool'],
        'threadSortNewestFirst' => ['secret' => false, 'lazy' => false, 'type' => 'bool'],
        'selectNewestInThread' => ['secret' => false, 'lazy' => false, 'type' => 'bool'],

        // Entra ID
        'tenantUrl' => ['secret' => false, 'lazy' => false, 'type' => 'string'],
    ];
}

class ExternalSettings {
    /** @var Array<string, array{'app': string, 'secret': bool, 'lazy': bool, 'type': 'string'|'int'|'float'|'bool'|'array'}> */
    public static array $availableSettings = [
        'retention_event_rooms' => ['app' => 'spreed', 'secret' => false, 'lazy' => false, 'type' => 'int'],
        'default_lobby_state' => ['app' => 'spreed', 'secret' => false, 'lazy' => false, 'type' => 'int'],

        // Entra ID
        'jwt-token' => ['app' => 'scimserviceprovider', 'secret' => false, 'lazy' => false, 'type' => 'string'],
        'ENTRA_GRAPH_CLIENT_ID' => ['app' => 'scimserviceprovider', 'secret' => false, 'lazy' => false, 'type' => 'string'],
        'ENTRA_GRAPH_CLIENT_SECRET' => ['app' => 'scimserviceprovider', 'secret' => true, 'lazy' => false, 'type' => 'string'],
        'ENTRA_GRAPH_TENANT_ID' => ['app' => 'scimserviceprovider', 'secret' => false, 'lazy' => false, 'type' => 'string'],
        'SYNC_GROUP_PATTERN' => ['app' => 'scimserviceprovider', 'secret' => false, 'lazy' => false, 'type' => 'string'],
    ];
}
