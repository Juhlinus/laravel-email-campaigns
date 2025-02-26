<?php

namespace Spatie\EmailCampaigns\Jobs;

use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\EmailCampaigns\Support\Config;
use Spatie\EmailCampaigns\Models\Campaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Spatie\EmailCampaigns\Events\CampaignSent;
use Spatie\EmailCampaigns\Models\Subscription;
use Spatie\EmailCampaigns\Actions\PrepareEmailHtmlAction;
use Spatie\EmailCampaigns\Actions\PrepareWebviewHtmlAction;

class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var \Spatie\EmailCampaigns\Models\Campaign */
    public $campaign;

    /** @var string */
    public $queue;

    public function __construct(Campaign $campaign)
    {
        $this->campaign = $campaign;

        $this->queue = config('email-campaigns.perform_on_queue.send_campaign_job');
    }

    public function handle()
    {
        if ($this->campaign->wasAlreadySent()) {
            return;
        }

        $this
            ->prepareEmailHtml()
            ->prepareWebviewHtml()
            ->send();
    }

    protected function prepareEmailHtml()
    {
        $action = Config::getActionClass('prepare_email_html_action', PrepareEmailHtmlAction::class);
        $action->execute($this->campaign);

        return $this;
    }

    private function prepareWebviewHtml()
    {
        $action = Config::getActionClass('prepare_webview_html_action', PrepareWebviewHtmlAction::class);
        $action->execute($this->campaign);

        return $this;
    }

    protected function send()
    {
        $this->campaign->emailList->subscriptions()->each(function (Subscription $emailListSubscription) {
            $pendingSend = $this->campaign->sends()->create([
                'email_list_subscription_id' => $emailListSubscription->id,
                'uuid' => (string) Str::uuid(),
            ]);

            dispatch(new SendMailJob($pendingSend));
        });

        $this->campaign->markAsSent($this->campaign->emailList->subscriptions->count());

        event(new CampaignSent($this->campaign));
    }
}
