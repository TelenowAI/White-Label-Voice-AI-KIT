<?php

/*
 * The kit API (contract/kit-api.yaml), under /api. Every route here is held to that document by
 * contract/tests, run against this backend: its status documented, its body valid, no undeclared field.
 *
 * health and brand are stateless. The setup wizard, signing in and the admin panel use the session cookie (the `web`
 * group: cookies, session, CSRF), behind PrepareInstallation, which lets them run on an install with no APP_KEY or
 * database yet. The admin panel also needs the install finished and a staff member signed in; invite links and the
 * customer console need the install finished, and the console's account routes a member signed in. Telenow's events
 * reach the kit after the install, with no session: their signature is the proof. So do a payment gateway's events
 * and the payer coming back from it.
 */

use App\Http\Controllers\Kit\Admin\AuditController;
use App\Http\Controllers\Kit\Admin\CaptchaController;
use App\Http\Controllers\Kit\Admin\CatalogController;
use App\Http\Controllers\Kit\Admin\CustomersController;
use App\Http\Controllers\Kit\Admin\CustomerTeamController;
use App\Http\Controllers\Kit\Admin\CustomerWalletController;
use App\Http\Controllers\Kit\Admin\EmailController;
use App\Http\Controllers\Kit\Admin\EmailTemplatesController;
use App\Http\Controllers\Kit\Admin\PaymentsController as AdminPaymentsController;
use App\Http\Controllers\Kit\Admin\OpenModelsController;
use App\Http\Controllers\Kit\Admin\PricingController;
use App\Http\Controllers\Kit\Admin\ProcessingCountryController;
use App\Http\Controllers\Kit\Admin\ReportsController;
use App\Http\Controllers\Kit\Admin\SmsController;
use App\Http\Controllers\Kit\Admin\StaffController;
use App\Http\Controllers\Kit\Admin\VoicePlatformController;
use App\Http\Controllers\Kit\Admin\SsoController;
use App\Http\Controllers\Kit\Admin\SignupsController;
use App\Http\Controllers\Kit\Admin\WebsiteController;
use App\Http\Controllers\Kit\Console\AgentCallsController;
use App\Http\Controllers\Kit\Console\AgentsController;
use App\Http\Controllers\Kit\Console\AgentFlowController;
use App\Http\Controllers\Kit\Console\AgentVersionsController;
use App\Http\Controllers\Kit\Console\AgentWebChatController;
use App\Http\Controllers\Kit\Console\InsightsController;
use App\Http\Controllers\Kit\Console\MemoryController;
use App\Http\Controllers\Kit\Console\AgentWebhooksController;
use App\Http\Controllers\Kit\Console\CallsController;
use App\Http\Controllers\Kit\Console\CallWorkController;
use App\Http\Controllers\Kit\Console\CatalogController as StackChoicesController;
use App\Http\Controllers\Kit\Console\CampaignsController;
use App\Http\Controllers\Kit\Console\ConsentController;
use App\Http\Controllers\Kit\Console\DncController;
use App\Http\Controllers\Kit\Console\KnowledgeController;
use App\Http\Controllers\Kit\Console\NumbersController;
use App\Http\Controllers\Kit\Console\PaymentsController;
use App\Http\Controllers\Kit\Console\SoftphoneController;
use App\Http\Controllers\Kit\Console\OwnVoicesController;
use App\Http\Controllers\Kit\Console\VoicesController;
use App\Http\Controllers\Kit\Console\TeamController;
use App\Http\Controllers\Kit\Console\WalletController;
use App\Http\Controllers\Kit\Console\WorkspaceController;
use App\Http\Controllers\Kit\EmailCodeController;
use App\Http\Controllers\Kit\InvitesController;
use App\Http\Controllers\Kit\AccountController;
use App\Http\Controllers\Kit\BrandController;
use App\Http\Controllers\Kit\ChatController;
use App\Http\Controllers\Kit\HealthController;
use App\Http\Controllers\Kit\OidcController;
use App\Http\Controllers\Kit\PasswordController;
use App\Http\Controllers\Kit\PaymentReturnController;
use App\Http\Controllers\Kit\PaymentWebhookController;
use App\Http\Controllers\Kit\PhoneCodeController;
use App\Http\Controllers\Kit\SessionController;
use App\Http\Controllers\Kit\StaffInvitesController;
use App\Http\Controllers\Kit\SetupController;
use App\Http\Controllers\Kit\SignInLinkController;
use App\Http\Controllers\Kit\SignupController;
use App\Http\Controllers\Kit\TelenowEventsController;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\EnsureCaptcha;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\PrepareInstallation;
use Illuminate\Support\Facades\Route;

Route::prefix('kit')->group(function () {
    Route::get('health', HealthController::class);
    Route::get('brand', BrandController::class);
    Route::post('telenow/events', TelenowEventsController::class)->middleware(EnsureInstalled::class);
    // Telenow asking, on a call to a routed number, who takes it: no session; the timed signature for the account's token is the proof.
    Route::post('telenow/routing/{token}', \App\Http\Controllers\Kit\TelenowRoutingController::class)->middleware(EnsureInstalled::class)->where('token', '[a-z0-9]{16,64}');
    // A payment gateway's events and the payer coming back from it: no session (a gateway's return can be a
    // cross-site POST, which carries no cookie); the gateway's signature, or asking the gateway, is the proof.
    Route::post('payments/webhooks/{gatewayId}', PaymentWebhookController::class)->middleware(EnsureInstalled::class);
    Route::match(['get', 'post'], 'payments/{paymentId}/return', PaymentReturnController::class)
        ->middleware([EnsureInstalled::class, 'throttle:kit-payment-return']);
    // A customer's chat box on their own website: no session (the browser is on another website, and sends no cookie); the browser's
    // `Origin` against the chat box's websites is the check (App\Kit\Chat\Conversations).
    // An agent's logo as an image: anyone may read it (its chat box on another website shows it); no session.
    Route::get('media/agent-logos/{agent}/{file}', [\App\Http\Controllers\Kit\AgentLogoController::class, 'show'])->middleware(EnsureInstalled::class)
        ->where('file', '[0-9a-f]{32}\.(png|jpg|webp)');
    Route::middleware(EnsureInstalled::class)->prefix('chat/{chatKey}')->group(function () {
        Route::get('/', [ChatController::class, 'show']);
        Route::get('messages', [ChatController::class, 'messages']);
        Route::post('messages', [ChatController::class, 'send']);
        Route::post('end', [ChatController::class, 'end']);
    });

    Route::middleware([PrepareInstallation::class, 'web'])->group(function () {
        Route::get('setup', [SetupController::class, 'show']);
        Route::post('setup/steps/{step}', [SetupController::class, 'submit']);
        Route::get('session', [SessionController::class, 'show']);
        Route::post('session', [SessionController::class, 'store'])->middleware(['throttle:kit-sign-in', EnsureCaptcha::class.':sign_in']);
        Route::delete('session', [SessionController::class, 'destroy']);
        Route::post('session/view-as/stop', [SessionController::class, 'stopViewingAs']);
        Route::middleware([EnsureInstalled::class, 'throttle:kit-password'])->group(function () {
            Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware(EnsureCaptcha::class.':password_reset');
            Route::get('password/reset/{token}', [PasswordController::class, 'show']);
            Route::post('password/reset/{token}', [PasswordController::class, 'reset']);
        });
        Route::middleware(EnsureInstalled::class)->group(function () {
            Route::get('signup', [SignupController::class, 'form']);
            Route::get('signup/verify/{token}', [SignupController::class, 'showVerification']);
            Route::get('signup/provider', [SignupController::class, 'showProvider']);
            Route::middleware('throttle:kit-signup')->group(function () {
                Route::post('signup', [SignupController::class, 'store'])->middleware(EnsureCaptcha::class.':sign_up');
                Route::post('signup/verify/{token}', [SignupController::class, 'verify']);
                Route::post('signup/provider', [SignupController::class, 'storeProvider'])->middleware(EnsureCaptcha::class.':sign_up');
                Route::post('session/link', [SignInLinkController::class, 'request'])->middleware(EnsureCaptcha::class.':sign_in_link');
                Route::post('session/link/{token}', [SignInLinkController::class, 'use']);
                Route::post('session/email-code', [EmailCodeController::class, 'request'])->middleware(EnsureCaptcha::class.':sign_in_email_code');
            });
            Route::middleware('throttle:kit-phone-code')->group(function () {
                Route::post('session/code', [PhoneCodeController::class, 'request'])->middleware(EnsureCaptcha::class.':sign_in_code');
                Route::post('account/phone', [AccountController::class, 'requestPhoneCode']);
                // A text costs the operator: slowed per number and address, and as a sign-up.
                Route::post('signup/phone', [SignupController::class, 'storePhone'])->middleware(['throttle:kit-signup', EnsureCaptcha::class.':sign_up']);
            });
            Route::middleware('throttle:kit-phone-verify')->group(function () {
                Route::post('session/code/verify', [PhoneCodeController::class, 'use']);
                Route::post('account/phone/verify', [AccountController::class, 'verifyPhone']);
                Route::post('signup/phone/verify', [SignupController::class, 'verifyPhone']);
                Route::post('session/email-code/verify', [EmailCodeController::class, 'use']);
                Route::post('signup/email/verify', [SignupController::class, 'verifyEmail']);
            });
            // Signing in with an OpenID Connect provider: off to the provider, and back (the session holds the state).
            Route::middleware('throttle:kit-oidc')->group(function () {
                Route::get('oidc/callback', [OidcController::class, 'callback']);
                Route::get('oidc/{providerId}/start', [OidcController::class, 'start']);
            });
            // The signed-in person's own account (401 signed out).
            Route::get('account', [AccountController::class, 'show']);
            Route::delete('account/phone', [AccountController::class, 'removePhone']);
        });

        // The admin panel: the operator's staff, after the install.
        Route::prefix('admin')->middleware([EnsureInstalled::class, EnsureStaff::class])->group(function () {
            // Every admin route names what it takes (config('kit.admin.abilities'), EnsureAbility).
            $may = fn (string $ability) => Route::middleware(EnsureAbility::class.':'.$ability);
            $may('reports')->group(function () {
                Route::get('dashboard', [ReportsController::class, 'dashboard']);
                Route::get('costs', [ReportsController::class, 'costs']);
            });
            $may('health')->group(function () {
                Route::get('telenow', [VoicePlatformController::class, 'show']);
                Route::post('telenow/writes/{id}/retry', [VoicePlatformController::class, 'retryWrite']);
                Route::post('telenow/events/{id}/retry', [VoicePlatformController::class, 'retryEvent']);
                Route::post('telenow/reconcile/{id}', [VoicePlatformController::class, 'reconcile']);
                Route::get('telenow/processing-country', [ProcessingCountryController::class, 'platform']);
            });
            $may('customers_read')->group(function () {
                Route::get('customers', [CustomersController::class, 'index']);
                Route::get('customers/{id}', [CustomersController::class, 'show']);
                Route::get('customers/{id}/team', [CustomerTeamController::class, 'index']);
                Route::get('customers/{id}/wallet', [CustomerWalletController::class, 'show']);
                Route::get('customers/{id}/processing-country', [ProcessingCountryController::class, 'customer']);
                Route::get('customers/{id}/call-health', [\App\Http\Controllers\Kit\Admin\CallHealthController::class, 'customer']);
                Route::get('customers/{id}/invoices', [\App\Http\Controllers\Kit\Admin\InvoicesController::class, 'customer']);
                Route::get('invoices/{id}', [\App\Http\Controllers\Kit\Admin\InvoicesController::class, 'show']);
            });
            $may('customers_suspend')->group(function () {
                Route::post('customers/{id}/suspend', [CustomersController::class, 'suspend']);
                Route::post('customers/{id}/resume', [CustomersController::class, 'resume']);
            });
            $may('customers_manage')->group(function () {
                Route::post('customers', [CustomersController::class, 'store']);
                Route::post('customers/{id}/retry', [CustomersController::class, 'retry']);
                Route::post('customers/{id}/team/invites', [CustomerTeamController::class, 'invite']);
                Route::delete('customers/{id}/team/invites/{inviteId}', [CustomerTeamController::class, 'revokeInvite']);
                Route::delete('customers/{id}/team/members/{userId}', [CustomerTeamController::class, 'removeMember']);
                Route::get('signups', [SignupsController::class, 'show']);
                Route::post('signups/{id}/approve', [SignupsController::class, 'approve']);
                Route::post('signups/{id}/reject', [SignupsController::class, 'reject']);
            });
            $may('customers_limits')->put('customers/{id}/limits', [CustomersController::class, 'limits']);
            $may('customers_close')->post('customers/{id}/close', [CustomersController::class, 'close']);
            $may('view_as')->post('customers/{id}/view-as', [CustomersController::class, 'viewAs']);
            $may('money')->group(function () {
                Route::post('customers/{id}/wallet/credits', [CustomerWalletController::class, 'credit']);
                Route::put('customers/{id}/plan', [CustomerWalletController::class, 'setPlan']);
                Route::get('pricing', [PricingController::class, 'show']);
                Route::put('pricing', [PricingController::class, 'update']);
                Route::put('pricing/low-balance', [PricingController::class, 'lowBalance']);
                Route::put('pricing/lists/{listId}', [PricingController::class, 'saveList']);
                Route::delete('pricing/lists/{listId}', [PricingController::class, 'deleteList']);
                Route::get('payments', [AdminPaymentsController::class, 'show']);
                Route::post('payments/{id}/confirm', [AdminPaymentsController::class, 'confirm']);
                Route::post('payments/{id}/cancel', [AdminPaymentsController::class, 'cancel']);
                Route::post('payments/{id}/refunds', [AdminPaymentsController::class, 'refund']);
                Route::get('platform-billing', [\App\Http\Controllers\Kit\Admin\PlatformBillingController::class, 'show']);
                Route::put('platform-billing/alert', [\App\Http\Controllers\Kit\Admin\PlatformBillingController::class, 'alert']);
                Route::get('invoice-settings', [\App\Http\Controllers\Kit\Admin\InvoicesController::class, 'settings']);
                Route::put('invoice-settings', [\App\Http\Controllers\Kit\Admin\InvoicesController::class, 'saveSettings']);
            });
            $may('payment_gateways')->group(function () {
                Route::put('payments/settings', [AdminPaymentsController::class, 'saveSettings']);
                Route::put('payments/order', [AdminPaymentsController::class, 'orderGateways']);
                Route::post('payments/gateways', [AdminPaymentsController::class, 'addGateway']);
                Route::put('payments/gateways/{id}', [AdminPaymentsController::class, 'updateGateway']);
                Route::delete('payments/gateways/{id}', [AdminPaymentsController::class, 'removeGateway']);
                Route::post('payments/gateways/{id}/test', [AdminPaymentsController::class, 'testGateway']);
            });
            $may('starters')->group(function () {
                Route::get('starters', [\App\Http\Controllers\Kit\Admin\StartersController::class, 'index']);
                Route::get('starters/preview', [\App\Http\Controllers\Kit\Admin\StartersController::class, 'preview']);
                Route::post('starters', [\App\Http\Controllers\Kit\Admin\StartersController::class, 'store']);
                Route::patch('starters/{id}', [\App\Http\Controllers\Kit\Admin\StartersController::class, 'update']);
            });
            $may('settings')->group(function () {
                Route::get('catalog', [CatalogController::class, 'show']);
                Route::put('catalog', [CatalogController::class, 'save']);
                Route::post('catalog/refresh', [CatalogController::class, 'refresh']);
                Route::get('catalog/voices', [CatalogController::class, 'voices']);
                Route::get('catalog/open-models', [OpenModelsController::class, 'index']);
                Route::get('catalog/open-models/price', [OpenModelsController::class, 'price']);
                Route::get('email', [EmailController::class, 'show']);
                Route::put('email', [EmailController::class, 'saveSite']);
                Route::post('email/senders', [EmailController::class, 'addSender']);
                Route::put('email/senders/{id}', [EmailController::class, 'updateSender']);
                Route::delete('email/senders/{id}', [EmailController::class, 'deleteSender']);
                Route::put('email/order', [EmailController::class, 'order']);
                Route::post('email/test', [EmailController::class, 'test']);
                Route::get('email/templates', [EmailTemplatesController::class, 'index']);
                Route::put('email/templates/{kind}', [EmailTemplatesController::class, 'save']);
                Route::delete('email/templates/{kind}', [EmailTemplatesController::class, 'reset']);
                Route::post('email/templates/{kind}/preview', [EmailTemplatesController::class, 'preview']);
                Route::get('sso', [SsoController::class, 'show']);
                Route::post('sso/providers', [SsoController::class, 'add']);
                Route::put('sso/providers/{id}', [SsoController::class, 'update']);
                Route::delete('sso/providers/{id}', [SsoController::class, 'delete']);
                Route::put('sso/order', [SsoController::class, 'order']);
                Route::get('captcha', [CaptchaController::class, 'show']);
                Route::put('captcha', [CaptchaController::class, 'save']);
                Route::get('website', [WebsiteController::class, 'show']);
                Route::put('website', [WebsiteController::class, 'save']);
                Route::get('sms', [SmsController::class, 'show']);
                Route::post('sms/senders', [SmsController::class, 'addSender']);
                Route::put('sms/senders/{id}', [SmsController::class, 'updateSender']);
                Route::delete('sms/senders/{id}', [SmsController::class, 'deleteSender']);
                Route::put('sms/order', [SmsController::class, 'order']);
                Route::post('sms/test', [SmsController::class, 'test']);
                Route::put('signups/settings', [SignupsController::class, 'saveSettings']);
            });
            $may('staff')->group(function () {
                Route::get('staff', [StaffController::class, 'index']);
                Route::post('staff/invites', [StaffController::class, 'invite']);
                Route::delete('staff/invites/{inviteId}', [StaffController::class, 'revokeInvite']);
                Route::put('staff/{userId}', [StaffController::class, 'update']);
                Route::delete('staff/{userId}', [StaffController::class, 'remove']);
            });
            $may('audit')->get('audit', [AuditController::class, 'index']);
        });

        Route::middleware(EnsureInstalled::class)->group(function () {
            // Invite links: public, the token is the secret; accepting signs the person in.
            Route::get('invites/{token}', [InvitesController::class, 'show']);
            Route::post('invites/{token}/accept', [InvitesController::class, 'accept'])->middleware('throttle:kit-invite-accept');
            Route::get('staff-invites/{token}', [StaffInvitesController::class, 'show']);
            Route::post('staff-invites/{token}/accept', [StaffInvitesController::class, 'accept'])->middleware('throttle:kit-invite-accept');

            // The customer console: the signed-in user's accounts, then the current one's routes (EnsureMember).
            Route::prefix('console')->group(function () {
                Route::get('workspaces', [WorkspaceController::class, 'index']);
                Route::post('workspace', [WorkspaceController::class, 'select']);
                Route::middleware(EnsureMember::class)->group(function () {
                    Route::get('home', [WorkspaceController::class, 'home']);
                    Route::get('team', [TeamController::class, 'index']);
                    Route::post('team/invites', [TeamController::class, 'invite']);
                    Route::delete('team/invites/{inviteId}', [TeamController::class, 'revokeInvite']);
                    Route::delete('team/members/{userId}', [TeamController::class, 'removeMember']);
                    Route::get('agents', [AgentsController::class, 'index']);
                    Route::post('agents', [AgentsController::class, 'store']);
                    Route::get('agents/settings-form', [AgentsController::class, 'settingsForm']);
                    Route::get('agents/{id}', [AgentsController::class, 'show']);
                    Route::patch('agents/{id}', [AgentsController::class, 'update']);
                    Route::delete('agents/{id}', [AgentsController::class, 'destroy']);
                    Route::post('agents/{id}/retry', [AgentsController::class, 'retry']);
                    Route::post('agents/{id}/duplicate', [AgentsController::class, 'duplicate']);
                    Route::get('starters', [\App\Http\Controllers\Kit\Console\StartersController::class, 'index']);
                    Route::post('starters/{id}/agents', [\App\Http\Controllers\Kit\Console\StartersController::class, 'store']);
                    Route::post('agents/{id}/test-call', [AgentsController::class, 'testCall']);
                    Route::get('agents/{id}/call-form', [AgentCallsController::class, 'form']);
                    Route::post('agents/{id}/calls', [AgentCallsController::class, 'place'])->middleware('throttle:kit-agent-call');
                    Route::post('agents/{id}/voice-preview', [AgentsController::class, 'voicePreview']);
                    Route::post('agents/{id}/check', [AgentsController::class, 'checkStack'])->middleware('throttle:kit-stack-check');
                    Route::post('agents/prompt-check', [AgentsController::class, 'checkNewPrompt'])->middleware('throttle:kit-prompt-check');
                    Route::get('call-health', [\App\Http\Controllers\Kit\Console\CallHealthController::class, 'show']);
                    Route::post('agents/{id}/prompt-check', [AgentsController::class, 'checkPrompt'])->middleware('throttle:kit-prompt-check');
                    Route::post('agents/{id}/logo', [AgentsController::class, 'setLogo']);
                    Route::delete('agents/{id}/logo', [AgentsController::class, 'removeLogo']);
                    Route::put('agents/{id}/knowledge', [KnowledgeController::class, 'setAgent']);
                    Route::get('agents/{id}/flow', [AgentFlowController::class, 'show']);
                    Route::put('agents/{id}/flow/draft', [AgentFlowController::class, 'saveDraft']);
                    Route::delete('agents/{id}/flow/draft', [AgentFlowController::class, 'discardDraft']);
                    Route::post('agents/{id}/flow/check', [AgentFlowController::class, 'check']);
                    Route::post('agents/{id}/flow/publish', [AgentFlowController::class, 'publish']);
                    Route::delete('agents/{id}/flow', [AgentFlowController::class, 'unpublish']);
                    Route::get('agents/{id}/versions', [AgentVersionsController::class, 'index']);
                    Route::get('agents/{id}/versions/{version}/changes', [AgentVersionsController::class, 'changes'])->where('version', '[0-9]{1,9}');
                    Route::get('agents/{id}/versions/{version}/results', [AgentVersionsController::class, 'results'])->where('version', '[0-9]{1,9}');
                    Route::post('agents/{id}/versions/{version}/restore', [AgentsController::class, 'restoreVersion'])->where('version', '[0-9]{1,9}');
                    Route::get('agents/{id}/web-chat', [AgentWebChatController::class, 'show']);
                    Route::put('agents/{id}/web-chat', [AgentWebChatController::class, 'update']);
                    Route::get('agents/{id}/webhooks', [AgentWebhooksController::class, 'index']);
                    Route::post('agents/{id}/webhooks', [AgentWebhooksController::class, 'store']);
                    Route::delete('agents/{id}/webhooks/{webhookId}', [AgentWebhooksController::class, 'destroy']);
                    Route::post('agents/{id}/webhooks/{webhookId}/test', [AgentWebhooksController::class, 'test']);
                    Route::get('agents/{id}/webhooks/{webhookId}/deliveries', [AgentWebhooksController::class, 'deliveries']);
                    Route::post('agents/{id}/webhooks/{webhookId}/deliveries/{deliveryId}/replay', [AgentWebhooksController::class, 'replay']);
                    Route::get('campaigns', [CampaignsController::class, 'index']);
                    Route::post('campaigns', [CampaignsController::class, 'store']);
                    Route::get('campaigns/{id}', [CampaignsController::class, 'show']);
                    Route::patch('campaigns/{id}', [CampaignsController::class, 'update']);
                    Route::delete('campaigns/{id}', [CampaignsController::class, 'destroy']);
                    Route::get('campaigns/{id}/targets', [CampaignsController::class, 'targets']);
                    Route::get('campaigns/{id}/targets/export', [CampaignsController::class, 'export']);
                    Route::post('campaigns/{id}/retry', [CampaignsController::class, 'retry']);
                    Route::get('campaigns/{id}/progress', [CampaignsController::class, 'progress']);
                    Route::post('campaigns/{id}/targets', [CampaignsController::class, 'addTargets']);
                    Route::put('campaigns/{id}/source', [CampaignsController::class, 'setSource']);
                    Route::delete('campaigns/{id}/source', [CampaignsController::class, 'removeSource']);
                    Route::post('campaigns/{id}/start', [CampaignsController::class, 'start']);
                    Route::post('campaigns/{id}/pause', [CampaignsController::class, 'pause']);
                    Route::post('campaigns/{id}/cancel', [CampaignsController::class, 'cancel']);
                    Route::get('campaigns/{id}/reach', [CampaignsController::class, 'reach']);
                    Route::get('campaigns/{id}/variables', [CampaignsController::class, 'variables']);
                    Route::get('knowledge', [KnowledgeController::class, 'index']);
                    Route::post('knowledge', [KnowledgeController::class, 'store']);
                    Route::get('knowledge/{id}', [KnowledgeController::class, 'show']);
                    Route::delete('knowledge/{id}', [KnowledgeController::class, 'destroy']);
                    Route::post('knowledge/{id}/documents', [KnowledgeController::class, 'addText']);
                    Route::post('knowledge/{id}/documents/upload', [KnowledgeController::class, 'upload']);
                    Route::post('knowledge/{id}/documents/page', [KnowledgeController::class, 'addPage']);
                    Route::post('knowledge/{id}/documents/{documentId}/retry', [KnowledgeController::class, 'retry']);
                    Route::delete('knowledge/{id}/documents/{documentId}', [KnowledgeController::class, 'destroyDocument']);
                    Route::get('knowledge/{id}/documents/{documentId}/table', [KnowledgeController::class, 'table']);
                    Route::patch('knowledge/{id}/documents/{documentId}/table', [KnowledgeController::class, 'setTableTypes']);
                    Route::get('catalog', [StackChoicesController::class, 'show']);
                    Route::get('voices', [VoicesController::class, 'index']);
                    Route::post('voices/preview', [VoicesController::class, 'preview']);
                    Route::post('voices/realtime-preview', [VoicesController::class, 'previewRealtime'])->middleware('throttle:kit-realtime-preview');
                    Route::put('voices/stars', [VoicesController::class, 'star']);
                    Route::delete('voices/stars', [VoicesController::class, 'unstar']);
                    Route::get('own-voices', [OwnVoicesController::class, 'index']);
                    Route::middleware('throttle:kit-own-voice')->group(function () {
                        Route::post('own-voices', [OwnVoicesController::class, 'store']);
                        Route::post('own-voices/{id}/sample', [OwnVoicesController::class, 'sample']);
                        Route::post('own-voices/{id}/clones', [OwnVoicesController::class, 'clone']);
                        Route::delete('own-voices/{id}/clones/{provider}', [OwnVoicesController::class, 'removeClone']);
                        Route::delete('own-voices/{id}', [OwnVoicesController::class, 'destroy']);
                    });
                    Route::get('wallet', [WalletController::class, 'show']);
                    Route::put('wallet/alert', [WalletController::class, 'alert']);
                    Route::get('payments', [PaymentsController::class, 'index']);
                    Route::post('payments', [PaymentsController::class, 'store']);
                    Route::get('payments/{id}', [PaymentsController::class, 'show']);
                    Route::get('invoices', [\App\Http\Controllers\Kit\Console\InvoicesController::class, 'index']);
                    Route::get('invoices/{id}', [\App\Http\Controllers\Kit\Console\InvoicesController::class, 'show']);
                    Route::get('billing-details', [\App\Http\Controllers\Kit\Console\InvoicesController::class, 'billingDetails']);
                    Route::put('billing-details', [\App\Http\Controllers\Kit\Console\InvoicesController::class, 'setBillingDetails']);
                    Route::get('numbers', [NumbersController::class, 'index']);
                    Route::get('numbers/available', [NumbersController::class, 'available']);
                    Route::post('numbers', [NumbersController::class, 'store']);
                    Route::put('numbers/{id}/agent', [NumbersController::class, 'agent']);
                    Route::put('numbers/{id}/routing', [NumbersController::class, 'routing']);
                    Route::put('numbers/{id}/caller-id', [NumbersController::class, 'callerId']);
                    Route::put('numbers/{id}/unanswered', [NumbersController::class, 'unanswered']);
                    Route::get('numbers/lost', [NumbersController::class, 'lost']);
                    Route::post('numbers/{id}/get-back', [NumbersController::class, 'getBack']);
                    Route::get('numbers/renewals', [NumbersController::class, 'renewals']);
                    Route::get('routing', [\App\Http\Controllers\Kit\Console\RoutingController::class, 'index']);
                    Route::post('routing/rules', [\App\Http\Controllers\Kit\Console\RoutingController::class, 'store']);
                    Route::patch('routing/rules/{id}', [\App\Http\Controllers\Kit\Console\RoutingController::class, 'update']);
                    Route::delete('routing/rules/{id}', [\App\Http\Controllers\Kit\Console\RoutingController::class, 'destroy']);
                    Route::put('routing/order', [\App\Http\Controllers\Kit\Console\RoutingController::class, 'order']);
                    Route::post('routing/try', [\App\Http\Controllers\Kit\Console\RoutingController::class, 'try']);
                    Route::post('routing/check', [\App\Http\Controllers\Kit\Console\RoutingController::class, 'check'])->middleware('throttle:kit-routing-check');
                    Route::delete('numbers/{id}', [NumbersController::class, 'destroy']);
                    Route::get('consent', [ConsentController::class, 'index']);
                    Route::get('consent/export', [ConsentController::class, 'export']);
                    Route::post('consent', [ConsentController::class, 'store']);
                    Route::post('consent/import', [ConsentController::class, 'import']);
                    Route::post('consent/withdraw', [ConsentController::class, 'withdraw']);
                    Route::put('consent/rule', [ConsentController::class, 'setRule']);
                    Route::get('dnc', [DncController::class, 'index']);
                    Route::get('dnc/export', [DncController::class, 'export']);
                    Route::post('dnc', [DncController::class, 'store']);
                    Route::post('dnc/import', [DncController::class, 'import']);
                    Route::delete('dnc/{id}', [DncController::class, 'destroy']);
                    Route::get('calls', [CallsController::class, 'index']);
                    Route::get('calls/live', [CallsController::class, 'live']);
                    Route::get('memory', [MemoryController::class, 'index']);
                    Route::delete('memory', [MemoryController::class, 'destroy']);
                    Route::get('calls/export', [CallsController::class, 'export']);
                    Route::get('insights', InsightsController::class);
                    Route::get('calls/{id}', [CallsController::class, 'show']);
                    Route::get('calls/{id}/recordings/{index}', [CallsController::class, 'recording'])->whereNumber('index');
                    Route::post('calls/{id}/listen', [CallsController::class, 'listen']);
                    Route::post('calls/{id}/agent-notes', [CallsController::class, 'tellAgent']);
                    Route::get('calls/{id}/work', [CallWorkController::class, 'show']);
                    Route::post('calls/{id}/notes', [CallWorkController::class, 'addNote']);
                    Route::put('calls/{id}/wrapup', [CallWorkController::class, 'setWrapup']);
                    Route::post('calls/{id}/followups', [CallWorkController::class, 'addFollowUp']);
                    Route::get('followups', [CallWorkController::class, 'followUps']);
                    Route::patch('followups/{id}', [CallWorkController::class, 'setFollowUp']);
                    Route::get('phone', [SoftphoneController::class, 'show']);
                    Route::post('phone/calls', [SoftphoneController::class, 'place'])->middleware('throttle:kit-browser-call');
                    Route::delete('phone/calls/{id}', [SoftphoneController::class, 'hangUp']);
                    Route::post('phone/calls/{id}/transfer', [SoftphoneController::class, 'transfer']);
                });
            });
        });
    });
});
