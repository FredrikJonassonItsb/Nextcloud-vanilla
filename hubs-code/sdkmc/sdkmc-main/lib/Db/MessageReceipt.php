<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getMessageId()
 * @method void setMessageId(string $messageId)
 * @method string getDocumentReference()
 * @method void setDocumentReference(string $documentReference)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getStatusCode()
 * @method void setStatusCode(string $statusCode)
 * @method ?string getStatusReason()
 * @method void setStatusReason(string $statusReason)
 * @method ?array<string, mixed> getReceiptData()
 * @method void setReceiptData(array<string, mixed> $receiptData)
 */
class MessageReceipt extends Entity implements \JsonSerializable {
    protected string $messageId = '';
    protected string $documentReference = '';
    protected string $status = '';
    protected ?string $statusCode = '';
    protected ?string $statusReason = '';
    /** @var ?array<string, mixed> */
    protected ?array $receiptData = [];

    public function __construct() {
        $this->addType('messageId', 'string');
        $this->addType('documentReference', 'string');
        $this->addType('status', 'string');
        $this->addType('statusCode', 'string');
        $this->addType('statusReason', 'string');
        $this->addType('receiptData', 'json');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        return [
            'id' => $this->id,
            'messageId' => $this->messageId,
            'documentReference' => $this->documentReference,
            'status' => $this->status,
            'statusCode' => $this->statusCode,
            'statusReason' => $this->statusReason,
            'receiptData' => $this->receiptData
        ];
    }
}
