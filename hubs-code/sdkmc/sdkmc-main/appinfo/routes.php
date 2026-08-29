<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$requirements = [
    'token' => '[a-z0-9]{4,30}',
];

return [
    'routes' => [
        ['name' => 'admin#addUserToMailBox', 'url' => '/api/v2/admin/addUserToMailBox', 'verb' => 'POST'],
        ['name' => 'admin#removeUserFromMailBox', 'url' => '/api/v2/admin/removeUserFromMailBox', 'verb' => 'POST'],
        ['name' => 'admin#addAccount', 'url' => '/api/v2/admin/addAccount', 'verb' => 'POST'],
        ['name' => 'admin#updateAccount', 'url' => '/api/v2/admin/updateAccount', 'verb' => 'PATCH'],
        ['name' => 'admin#removeAccount', 'url' => '/api/v2/admin/removeAccount', 'verb' => 'POST'],
        ['name' => 'admin#serverSettings', 'url' => '/api/v2/admin/serversettings', 'verb' => 'POST'],
        ['name' => 'admin#getServerSettings', 'url' => '/api/v2/admin/serversettings', 'verb' => 'GET'],
        ['name' => 'admin#updateAddressBook', 'url' => '/api/v2/admin/updateAddressBook', 'verb' => 'GET'],
        ['name' => 'admin#runExpungeNow', 'url' => '/api/v2/admin/runExpungeNow', 'verb' => 'GET'],
        ['name' => 'admin#provisionPersonligAccounts', 'url' => '/api/v2/admin/provisionPersonligAccounts', 'verb' => 'POST'],
        ['name' => 'admin#addGroupToMailBox', 'url' => '/api/v2/admin/addGroupToMailBox', 'verb' => 'POST'],
        ['name' => 'admin#removeGroupFromMailBox', 'url' => '/api/v2/admin/removeGroupFromMailBox', 'verb' => 'POST'],
        ['name' => 'admin#propagateActivityDefaults', 'url' => '/api/v2/admin/propagateActivityDefaults', 'verb' => 'POST'],
        ['name' => 'admin#getActivityNotificationStatus', 'url' => '/api/v2/admin/activityNotificationStatus', 'verb' => 'GET'],

        ['name' => 'app_info#getSecureMailData', 'url' => '/api/v2/securemail/secureMailData', 'verb' => 'GET'],
        ['name' => 'app_info#getInfo', 'url' => '/api/v2/frontend/getSettings', 'verb' => 'GET'],

        ['name' => 'sms#sendAuthCode', 'url' => '/api/v2/securemail/sms/send-auth-code', 'verb' => 'POST'],
        ['name' => 'mail_box#internalMailboxes', 'url' => '/api/v2/securemail/internalMailboxes', 'verb' => 'GET'],
        ['name' => 'mail_box#internalMailboxesAB', 'url' => '/api/v2/securemail/internalMailboxesAB', 'verb' => 'GET'],
        ['name' => 'mail_box#existingAddressesToken', 'url' => '/api/v2/sdkmw/existingAddresses', 'verb' => 'GET'],
        ['name' => 'mail_box#existingAddresses', 'url' => '/api/v2/frontend/sdk/existingAddresses', 'verb' => 'GET'],
        ['name' => 'mail_box#existingAllMailboxes', 'url' => '/api/v2/sdkmc/allMailboxes', 'verb' => 'GET'],
        ['name' => 'mail_box#existingAllUsers', 'url' => '/api/v2/sdkmc/allUsers', 'verb' => 'GET'],
        ['name' => 'mail_box#getUserMailboxes', 'url' => '/api/v2/sdkmc/user-mailboxes', 'verb' => 'GET'],

        ['name' => 'mail_notification#redirect', 'url' => '/mailbox-link/{itslMailboxId}', 'verb' => 'GET'],

        ['name' => 'message_thread#save', 'url' => '/api/v2/sdkmw/messageThread', 'verb' => 'POST'],
        ['name' => 'message_thread#getByEmailMessage', 'url' => '/api/v2/sdkmw/messageThread/email/message/{messageId}', 'verb' => 'GET'],
        ['name' => 'message_thread#getBySdkMessage', 'url' => '/api/v2/sdkmw/messageThread/sdk/message/{sdkMessageId}', 'verb' => 'GET'],
        ['name' => 'message_thread#getBySdkConversation', 'url' => '/api/v2/sdkmw/messageThread/sdk/conversation/{conversationId}', 'verb' => 'GET'],

        ['name' => 'message_receipt#save', 'url' => '/api/v2/sdkmw/messageReceipt', 'verb' => 'POST'],
        ['name' => 'message_receipt#get', 'url' => '/api/v2/sdkmw/messageReceipt/{messageId}', 'verb' => 'GET'],

        ['name' => 'address_book#show', 'url' => '/api/v2/frontend/sdk/addressbook/api/{type}', 'verb' => 'GET'],

        ['name' => 'sdk_log#save', 'url' => '/api/v2/iipax/sdkLog', 'verb' => 'POST'],
        ['name' => 'sdk_log#get', 'url' => '/api/v2/iipax/sdkLog', 'verb' => 'GET'],

        ['name' => 'token_proxy#tokenLegacy', 'url' => '/token', 'verb' => 'POST'],
        ['name' => 'token_proxy#token', 'url' => '/token/{loginProvider}/{loginType}/{loginMethod}', 'verb' => 'POST'],
        ['name' => 'talk#authorize', 'url' => '/api/v2/spreed/authorize', 'verb' => 'GET'],
        ['name' => 'talk#callback', 'url' => '/api/v2/spreed/callback', 'verb' => 'GET'],
        ['name' => 'talk#logout', 'url' => '/spreed/guest/logout/{token}', 'verb' => 'GET', 'requirements' => $requirements],
        ['name' => 'talk#authError', 'url' => '/spreed/guest/auth-error', 'verb' => 'GET'],
        ['name' => 'talk#fullGuestName', 'url' => '/api/v2/spreed/guest-identity/{token}/{actorId}', 'verb' => 'GET', 'requirements' => $requirements],

        ['name' => 'loa3#upgrade', 'url' => '/upgradeToLoa3', 'verb' => 'GET'],
        ['name' => 'loa3#redirect', 'url' => '/redirectToLoa3', 'verb' => 'GET'],

        ['name' => 'guest#name', 'url' => 'guest/name/{token}', 'verb' => 'GET', 'requirements' => $requirements],
        ['name' => 'guest#update', 'url' => '/guest/update/{name}', 'verb' => 'GET'],

        ['name' => 'EventSmsIntent#store',  'url' => '/api/v2/calendar/event-sms-intent',         'verb' => 'POST'],
        ['name' => 'EventSmsIntent#delete', 'url' => '/api/v2/calendar/event-sms-intent/delete',  'verb' => 'POST'],
        ['name' => 'EventBankIDIntent#store',  'url' => '/api/v2/calendar/event-bankid-intent',         'verb' => 'POST'],
        ['name' => 'EventBankIDIntent#delete',  'url' => '/api/v2/calendar/event-bankid-intent/delete',         'verb' => 'POST'],
        ['name' => 'EventSecuremailInviteIntent#store',  'url' => '/api/v2/calendar/event-securemail-invite-intent', 'verb' => 'POST'],
        ['name' => 'EventSecuremailInviteIntent#delete',  'url' => '/api/v2/calendar/event-securemail-invite-intent/delete', 'verb' => 'POST'],

        ['name' => 'tag_file#assign', 'url' => '/api/v2/tag/assign', 'verb' => 'POST'],

        // ITSL Tag API
        ['name' => 'itsl_tag#createTag', 'url' => '/api/tags/{accountId}', 'verb' => 'POST'],
        ['name' => 'itsl_tag#updateTag', 'url' => '/api/tags/{accountId}/{id}', 'verb' => 'PUT'],
        ['name' => 'itsl_tag#deleteTag', 'url' => '/api/tags/{accountId}/delete/{id}', 'verb' => 'DELETE'],
        // [HUBS-ARENDE-KRAV 2026-07-12] deterministic, accountId-less case-tag purge
        // for NEVER-SoR gallring (only touches the reserved `case:` namespace).
        ['name' => 'itsl_tag#deleteCaseTagByLabel', 'url' => '/api/tags/by-label/{imapLabel}', 'verb' => 'DELETE'],
        ['name' => 'itsl_tag#setMessageTag', 'url' => '/api/messages/{id}/tags/{imapLabel}', 'verb' => 'PUT'],
        ['name' => 'itsl_tag#removeMessageTag', 'url' => '/api/messages/{id}/tags/{imapLabel}', 'verb' => 'DELETE'],

        // Bulk thread operations
        ['name' => 'itsl_tag#setThreadTag', 'url' => '/api/thread/tags/{imapLabel}', 'verb' => 'PUT'],
        ['name' => 'itsl_tag#removeThreadTag', 'url' => '/api/thread/tags/{imapLabel}', 'verb' => 'DELETE'],
        ['name' => 'itsl_tag#setThreadFlags', 'url' => '/api/thread/flags', 'verb' => 'PUT'],
    ]
];
