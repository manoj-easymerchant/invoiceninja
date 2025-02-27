<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Jobs\PostMark;

use App\DataMapper\Analytics\Mail\EmailBounce;
use App\DataMapper\Analytics\Mail\EmailSpam;
use App\Jobs\Util\SystemLogger;
use App\Libraries\MultiDB;
use App\Models\CreditInvitation;
use App\Models\InvoiceInvitation;
use App\Models\PurchaseOrderInvitation;
use App\Models\QuoteInvitation;
use App\Models\RecurringInvoiceInvitation;
use App\Models\SystemLog;
use App\Notifications\Ninja\EmailSpamNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Postmark\PostmarkClient;
use Turbo124\Beacon\Facades\LightLogs;

class ProcessPostmarkWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $invitation;

    private $entity;

    private array $default_response =  [
        'recipients' => '',
        'subject' => 'Message not found.',
        'entity' => '',
        'entity_id' => '',
        'events' => [],
    ];

    /**
     * Create a new job instance.
     *
     */
    public function __construct(private array $request)
    {
    }

    private function getSystemLog(string $message_id): ?SystemLog
    {
        return SystemLog::query()
                ->where('company_id', $this->invitation->company_id)
                ->where('type_id', SystemLog::TYPE_WEBHOOK_RESPONSE)
                ->whereJsonContains('log', ['MessageID' => $message_id])
                ->orderBy('id', 'desc')
                ->first();

    }

    private function updateSystemLog(SystemLog $system_log, array $data): void
    {
        $system_log->log = $data;
        $system_log->save();
    }

    /**
     * Execute the job.
     *
     *
     * @return void
     */
    public function handle()
    {
        MultiDB::findAndSetDbByCompanyKey($this->request['Tag']);
        
        $this->invitation = $this->discoverInvitation($this->request['MessageID']);

        if (!$this->invitation) {
            return;
        }

        if (array_key_exists('Details', $this->request)) {
            $this->invitation->email_error = $this->request['Details'];
        }
        
        switch ($this->request['RecordType']) {
            case 'Delivery':
                return $this->processDelivery();
            case 'Bounce':
                return $this->processBounce();
            case 'SpamComplaint':
                return $this->processSpamComplaint();
            case 'Open':
                return $this->processOpen();
            default:
                # code...
                break;
        }
    }

    // {
    //   "Metadata": {
    //     "example": "value",
    //     "example_2": "value"
    //   },
    //   "RecordType": "Open",
    //   "FirstOpen": true,
    //   "Client": {
    //     "Name": "Chrome 35.0.1916.153",
    //     "Company": "Google",
    //     "Family": "Chrome"
    //   },
    //   "OS": {
    //     "Name": "OS X 10.7 Lion",
    //     "Company": "Apple Computer, Inc.",
    //     "Family": "OS X 10"
    //   },
    //   "Platform": "WebMail",
    //   "UserAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_7_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/35.0.1916.153 Safari/537.36",
    //   "ReadSeconds": 5,
    //   "Geo": {
    //     "CountryISOCode": "RS",
    //     "Country": "Serbia",
    //     "RegionISOCode": "VO",
    //     "Region": "Autonomna Pokrajina Vojvodina",
    //     "City": "Novi Sad",
    //     "Zip": "21000",
    //     "Coords": "45.2517,19.8369",
    //     "IP": "188.2.95.4"
    //   },
    //   "MessageID": "00000000-0000-0000-0000-000000000000",
    //   "MessageStream": "outbound",
    //   "ReceivedAt": "2022-02-06T06:37:48Z",
    //   "Tag": "welcome-email",
    //   "Recipient": "john@example.com"
    // }

    private function processOpen()
    {
        $this->invitation->opened_date = now();
        $this->invitation->save();

        $data = array_merge($this->request, ['history' => $this->fetchMessage()]);

        $sl = $this->getSystemLog($this->request['MessageID']);

        if($sl) {
            $this->updateSystemLog($sl, $data);
            return;
        }

        (new SystemLogger(
            $data,
            SystemLog::CATEGORY_MAIL,
            SystemLog::EVENT_MAIL_OPENED,
            SystemLog::TYPE_WEBHOOK_RESPONSE,
            $this->invitation->contact->client,
            $this->invitation->company
        ))->handle();
    }

    // {
    //   "RecordType": "Delivery",
    //   "ServerID": 23,
    //   "MessageStream": "outbound",
    //   "MessageID": "00000000-0000-0000-0000-000000000000",
    //   "Recipient": "john@example.com",
    //   "Tag": "welcome-email",
    //   "DeliveredAt": "2021-02-21T16:34:52Z",
    //   "Details": "Test delivery webhook details",
    //   "Metadata": {
    //     "example": "value",
    //     "example_2": "value"
    //   }
    // }
    private function processDelivery()
    {
        $this->invitation->email_status = 'delivered';
        $this->invitation->save();

        $data = array_merge($this->request, ['history' => $this->fetchMessage()]);

        $sl = $this->getSystemLog($this->request['MessageID']);

        if($sl) {
            $this->updateSystemLog($sl, $data);
            return;
        }

        (new SystemLogger(
            $data,
            SystemLog::CATEGORY_MAIL,
            SystemLog::EVENT_MAIL_DELIVERY,
            SystemLog::TYPE_WEBHOOK_RESPONSE,
            $this->invitation->contact->client,
            $this->invitation->company
        ))->handle();
    }

    // {
    //   "Metadata": {
    //     "example": "value",
    //     "example_2": "value"
    //   },
    //   "RecordType": "Bounce",
    //   "ID": 42,
    //   "Type": "HardBounce",
    //   "TypeCode": 1,
    //   "Name": "Hard bounce",
    //   "Tag": "Test",
    //   "MessageID": "00000000-0000-0000-0000-000000000000",
    //   "ServerID": 1234,
    //   "MessageStream": "outbound",
    //   "Description": "The server was unable to deliver your message (ex: unknown user, mailbox not found).",
    //   "Details": "Test bounce details",
    //   "Email": "john@example.com",
    //   "From": "sender@example.com",
    //   "BouncedAt": "2021-02-21T16:34:52Z",
    //   "DumpAvailable": true,
    //   "Inactive": true,
    //   "CanActivate": true,
    //   "Subject": "Test subject",
    //   "Content": "Test content"
    // }

    private function processBounce()
    {
        $this->invitation->email_status = 'bounced';
        $this->invitation->save();

        $bounce = new EmailBounce(
            $this->request['Tag'],
            $this->request['From'],
            $this->request['MessageID']
        );

        LightLogs::create($bounce)->send();

        $data = array_merge($this->request, ['history' => $this->fetchMessage()]);

        $sl = $this->getSystemLog($this->request['MessageID']);

        if($sl) {
            $this->updateSystemLog($sl, $data);
            return;
        }

        (new SystemLogger($data, SystemLog::CATEGORY_MAIL, SystemLog::EVENT_MAIL_BOUNCED, SystemLog::TYPE_WEBHOOK_RESPONSE, $this->invitation->contact->client, $this->invitation->company))->handle();

        // if(config('ninja.notification.slack'))
        // $this->invitation->company->notification(new EmailBounceNotification($this->invitation->company->account))->ninja();
    }

    // {
    //   "Metadata": {
    //     "example": "value",
    //     "example_2": "value"
    //   },
    //   "RecordType": "SpamComplaint",
    //   "ID": 42,
    //   "Type": "SpamComplaint",
    //   "TypeCode": 100001,
    //   "Name": "Spam complaint",
    //   "Tag": "Test",
    //   "MessageID": "00000000-0000-0000-0000-000000000000",
    //   "ServerID": 1234,
    //   "MessageStream": "outbound",
    //   "Description": "The subscriber explicitly marked this message as spam.",
    //   "Details": "Test spam complaint details",
    //   "Email": "john@example.com",
    //   "From": "sender@example.com",
    //   "BouncedAt": "2021-02-21T16:34:52Z",
    //   "DumpAvailable": true,
    //   "Inactive": true,
    //   "CanActivate": false,
    //   "Subject": "Test subject",
    //   "Content": "Test content"
    // }
    private function processSpamComplaint()
    {
        $this->invitation->email_status = 'spam';
        $this->invitation->save();

        $spam = new EmailSpam(
            $this->request['Tag'],
            $this->request['From'],
            $this->request['MessageID']
        );

        LightLogs::create($spam)->send();

        $data = array_merge($this->request, ['history' => $this->fetchMessage()]);

        $sl = $this->getSystemLog($this->request['MessageID']);

        if($sl) {
            $this->updateSystemLog($sl, $data);
            return;
        }

        (new SystemLogger($data, SystemLog::CATEGORY_MAIL, SystemLog::EVENT_MAIL_SPAM_COMPLAINT, SystemLog::TYPE_WEBHOOK_RESPONSE, $this->invitation->contact->client, $this->invitation->company))->handle();

        if (config('ninja.notification.slack')) {
            $this->invitation->company->notification(new EmailSpamNotification($this->invitation->company->account))->ninja();
        }
    }

    private function discoverInvitation($message_id)
    {
        $invitation = false;

        if ($invitation = InvoiceInvitation::where('message_id', $message_id)->first()) {
            $this->entity = 'invoice';
            return $invitation;
        } elseif ($invitation = QuoteInvitation::where('message_id', $message_id)->first()) {
            $this->entity = 'quote';
            return $invitation;
        } elseif ($invitation = RecurringInvoiceInvitation::where('message_id', $message_id)->first()) {
            $this->entity = 'recurring_invoice';
            return $invitation;
        } elseif ($invitation = CreditInvitation::where('message_id', $message_id)->first()) {
            $this->entity = 'credit';
            return $invitation;
        } elseif ($invitation = PurchaseOrderInvitation::where('message_id', $message_id)->first()) {
            $this->entity = 'purchase_order';
            return $invitation;
        } else {
            return $invitation;
        }
    }

    public function getRawMessage(string $message_id)
    {

        $postmark = new PostmarkClient(config('services.postmark.token'));
        $messageDetail = $postmark->getOutboundMessageDetails($message_id);
        return $messageDetail;
        
    }


    public function getBounceId(string $message_id): ?int
    {

        $messageDetail = $this->getRawMessage($message_id);

        
        $event =  collect($messageDetail->messageevents)->first(function ($event) {

            return $event?->Details?->BounceID ?? false;

        });

        return $event?->Details?->BounceID ?? null;

    }

    private function fetchMessage(): array
    {
        if(strlen($this->request['MessageID']) < 1) {
            return $this->default_response;
        }
    
        try {

            $postmark = new PostmarkClient(config('services.postmark.token'));
            $messageDetail = $postmark->getOutboundMessageDetails($this->request['MessageID']);

            $recipients = collect($messageDetail['recipients'])->flatten()->implode(',');
            $subject = $messageDetail->subject ?? '';

            $events =  collect($messageDetail->messageevents)->map(function ($event) {

                return [
                        'bounce_id' => $event?->Details?->BounceID ?? '',
                        'recipient' => $event->Recipient ?? '',
                        'status' => $event->Type ?? '',
                        'delivery_message' => $event->Details->DeliveryMessage ?? $event->Details->Summary ?? '',
                        'server' => $event->Details->DestinationServer ??  '',
                        'server_ip' => $event->Details->DestinationIP ?? '',
                        'date' => \Carbon\Carbon::parse($event->ReceivedAt)->format('Y-m-d H:i:s') ?? '',
                    ];

            })->toArray();

            return [
                'recipients' => $recipients,
                'subject' => $subject,
                'entity' => $this->entity ?? '',
                'entity_id' => $this->invitation->{$this->entity}->hashed_id ?? '',
                'events' => $events,
            ];

        } catch (\Exception $e) {

            return $this->default_response;

        }
    }
}
