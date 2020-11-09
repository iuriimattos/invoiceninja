<?php

namespace App\Jobs\Payment;

use App\DataMapper\Analytics\EmailInvoiceFailure;
use App\Events\Invoice\InvoiceWasEmailed;
use App\Events\Invoice\InvoiceWasEmailedAndFailed;
use App\Events\Payment\PaymentWasEmailed;
use App\Events\Payment\PaymentWasEmailedAndFailed;
use App\Helpers\Email\BuildEmail;
use App\Jobs\Mail\BaseMailerJob;
use App\Jobs\Utils\SystemLogger;
use App\Libraries\MultiDB;
use App\Mail\Engine\PaymentEmailEngine;
use App\Mail\TemplateEmail;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\Payment;
use App\Models\SystemLog;
use App\Utils\Ninja;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Turbo124\Beacon\Facades\LightLogs;

class EmailPayment extends BaseMailerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payment;

    public $email_builder;

    private $contact;

    private $company;

    public $settings;
    /**
     * Create a new job instance.
     *
     * @param Payment $payment
     * @param $email_builder
     * @param $contact
     * @param $company
     */
    public function __construct(Payment $payment, Company $company, ClientContact $contact)
    {
        $this->payment = $payment;
        $this->contact = $contact;
        $this->company = $company;
        $this->settings = $payment->client->getMergedSettings();
    }

    /**
     * Execute the job.
     *
     *
     * @return void
     */
    public function handle()
    {
        if($this->company->is_disabled)
            return true;
        
        if ($this->contact->email) {

            MultiDB::setDb($this->company->db); 

            //if we need to set an email driver do it now
            $this->setMailDriver();

            $email_builder = (new PaymentEmailEngine($this->payment, $this->contact))->build();

            Mail::to($this->contact->email, $this->contact->present()->name())
                ->send(new TemplateEmail($email_builder, $this->contact->user, $this->contact->client));

            if (count(Mail::failures()) > 0) {
                event(new PaymentWasEmailedAndFailed($this->payment, Mail::failures(), Ninja::eventVars()));

                return $this->logMailError(Mail::failures());
            }

            event(new PaymentWasEmailed($this->payment, $this->payment->company, Ninja::eventVars()));

        }
    }

    public function failed($exception = null)
    {
        info('the job failed');

        $job_failure = new EmailInvoiceFailure();
        $job_failure->string_metric5 = 'payment';
        $job_failure->string_metric6 = $exception->getMessage();

        LightLogs::create($job_failure)
                 ->batch();

    }

}
