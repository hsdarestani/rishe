<?php

declare(strict_types=1);

namespace Rishe\Tax\Application;

use Rishe\Tax\Domain\Exception\TaxDomainException;
use Rishe\Tax\Domain\TaxInvoiceStatus;
use Rishe\Tax\Domain\TaxInvoiceSubject;

trait TaxSubmissionOperations
{
    public function submit(int $invoiceId, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($invoiceId, $actor): array {
            $invoice = $this->repository->invoiceForUpdate($this->positiveId($invoiceId, 'invoice_id'));
            if ($invoice === null) {
                throw new TaxDomainException('Tax invoice not found.');
            }
            $status = TaxInvoiceStatus::tryFrom((string) $invoice['status']);
            if ($status === TaxInvoiceStatus::ACCEPTED) {
                return $this->requireInvoice((int) $invoice['id']);
            }
            if ($status === null) {
                throw new TaxDomainException('Tax invoice status is invalid.');
            }
            $status->assertCanSubmit();
            $profile = $this->requireProfile((int) $invoice['profile_id']);
            $response = $this->gateways->gateway($profile)->submit($profile, $invoice);
            $remoteStatus = strtolower((string) ($response['status'] ?? 'submitted'));
            $resolved = match ($remoteStatus) {
                'accepted', 'success', 'confirmed' => TaxInvoiceStatus::ACCEPTED,
                'rejected', 'failed', 'error' => TaxInvoiceStatus::REJECTED,
                default => TaxInvoiceStatus::SUBMITTED,
            };
            $reference = $this->nullableText($response['reference_number'] ?? null, 191);
            $uid = $this->nullableText($response['uid'] ?? null, 191);
            $errorCode = $this->nullableText($response['error_code'] ?? null, 100);
            $errorMessage = $this->nullableText($response['error_message'] ?? null, 1000);
            $attempt = (int) ($invoice['submission_attempts'] ?? 0) + 1;
            $this->repository->recordSubmission((int) $invoice['id'], [
                'attempt_number' => $attempt,
                'request_hash' => hash('sha256', (string) $invoice['payload_json']),
                'response_hash' => hash('sha256', json_encode($response, JSON_THROW_ON_ERROR)),
                'reference_number' => $reference,
                'uid' => $uid,
                'status' => $resolved->value,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'actor_user_id' => $actor,
            ]);
            $this->repository->updateInvoiceStatus(
                (int) $invoice['id'],
                $resolved->value,
                $reference,
                $uid,
                $errorCode,
                $errorMessage
            );
            $this->repository->recordStatus((int) $invoice['id'], [
                'status' => $resolved->value,
                'source' => 'submit',
                'reference_number' => $reference,
                'payload_hash' => hash('sha256', json_encode($response, JSON_THROW_ON_ERROR)),
                'message' => $errorMessage,
                'actor_user_id' => $actor,
            ]);
            if ($resolved === TaxInvoiceStatus::ACCEPTED && ($invoice['source_invoice_id'] ?? null) !== null) {
                $subject = TaxInvoiceSubject::tryFrom((int) $invoice['subject_code']);
                $terminal = $subject?->terminalStatus();
                if ($terminal !== null) {
                    $this->repository->markSourceDerived(
                        (int) $invoice['source_invoice_id'],
                        $terminal->value,
                        (int) $invoice['id']
                    );
                }
            }
            $this->audit->record('tax.invoice.submitted', 'tax_invoice', (string) $invoice['id'], [
                'status' => $resolved->value,
                'reference_number' => $reference,
                'attempt' => $attempt,
            ]);

            return $this->requireInvoice((int) $invoice['id']);
        });
    }

    public function inquire(int $invoiceId, int $actorUserId): array
    {
        $actor = $this->actor($actorUserId);

        return $this->transactions->run(function () use ($invoiceId, $actor): array {
            $invoice = $this->repository->invoiceForUpdate($this->positiveId($invoiceId, 'invoice_id'));
            if ($invoice === null || empty($invoice['reference_number'])) {
                throw new TaxDomainException('Submitted tax invoice reference is required for inquiry.');
            }
            $profile = $this->requireProfile((int) $invoice['profile_id']);
            $response = $this->gateways->gateway($profile)->inquire(
                $profile,
                (string) $invoice['reference_number']
            );
            $remoteStatus = strtolower((string) ($response['status'] ?? 'submitted'));
            $resolved = match ($remoteStatus) {
                'accepted', 'success', 'confirmed' => TaxInvoiceStatus::ACCEPTED,
                'rejected', 'failed', 'error' => TaxInvoiceStatus::REJECTED,
                default => TaxInvoiceStatus::SUBMITTED,
            };
            $errorCode = $this->nullableText($response['error_code'] ?? null, 100);
            $errorMessage = $this->nullableText($response['error_message'] ?? null, 1000);
            $this->repository->updateInvoiceStatus(
                (int) $invoice['id'],
                $resolved->value,
                (string) $invoice['reference_number'],
                $this->nullableText($response['uid'] ?? $invoice['remote_uid'] ?? null, 191),
                $errorCode,
                $errorMessage
            );
            $this->repository->recordStatus((int) $invoice['id'], [
                'status' => $resolved->value,
                'source' => 'inquiry',
                'reference_number' => (string) $invoice['reference_number'],
                'payload_hash' => hash('sha256', json_encode($response, JSON_THROW_ON_ERROR)),
                'message' => $errorMessage,
                'actor_user_id' => $actor,
            ]);
            $this->audit->record('tax.invoice.inquired', 'tax_invoice', (string) $invoice['id'], [
                'status' => $resolved->value,
                'reference_number' => $invoice['reference_number'],
            ]);

            return $this->requireInvoice((int) $invoice['id']);
        });
    }
}
