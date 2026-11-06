<?php

/**
 * Paying the operator (App\Kit\Payments, the payments controllers, PaymentsStep): each driver's name and form, the
 * payment methods a gateway can show, the operator's dispute rules, and the words each refusal answers with. Customers
 * read the console's answers: nothing names the platform's own provider.
 */
return [
    'fields' => [
        'driver' => 'Gateway',
        'name' => 'Name customers see',
        'mode' => 'Mode',
        'methods' => 'Payment methods',
        'payerEmail' => 'Email',
        'plans' => 'Plans',
        'enabled' => 'Enabled',
        'minAmount' => 'Smallest top-up',
        'maxAmount' => 'Largest top-up',
        'disputeAction' => 'When a payment is disputed',
        'gatewayId' => 'How to pay',
        'amount' => 'Amount',
        'reference' => 'Reference',
    ],
    'drivers' => [
        'manual' => [
            'name' => 'Bank transfer',
            'fields' => ['instructions' => 'How to pay'],
            'help' => ['instructions' => 'Shown to customers with a reference to quote: your bank details, or whatever they should do. Confirm each payment in Admin → Payments when the money arrives.'],
        ],
        'razorpay' => [
            'name' => 'Razorpay',
            'fields' => ['keyId' => 'Key ID', 'keySecret' => 'Key secret', 'webhookSecret' => 'Webhook secret'],
            'help' => [
                'keyId' => 'From Account & Settings > API Keys in your Razorpay Dashboard. Test keys start rzp_test_, live keys rzp_live_.',
                'keySecret' => 'Shown once, when you make the key. It is stored encrypted.',
                'webhookSecret' => 'The secret you choose when adding the webhook in your Razorpay Dashboard, at the address and with the events shown here once this is saved. Payments must capture automatically in your Razorpay settings.',
            ],
        ],
        'stripe' => [
            'name' => 'Stripe',
            'fields' => ['secretKey' => 'Secret key', 'webhookSecret' => 'Webhook signing secret'],
            'help' => [
                'secretKey' => 'From API keys in your Stripe Dashboard, starting sk_test_ or sk_live_. A restricted key (rk_) works if it may write Checkout Sessions and Refunds and read PaymentIntents. It is stored encrypted.',
                'webhookSecret' => 'Starts whsec_. Add a webhook destination in Stripe Workbench with the address and events shown here once this is saved, choosing snapshot events, and copy its signing secret.',
            ],
        ],
        'paypal' => [
            'name' => 'PayPal',
            'whole_amounts' => 'PayPal takes only whole amounts in :currency.',
            'fields' => ['clientId' => 'Client ID', 'clientSecret' => 'Secret', 'webhookId' => 'Webhook ID'],
            'help' => [
                'clientId' => 'From Apps & Credentials in your PayPal Developer Dashboard. Sandbox and live have separate credentials.',
                'clientSecret' => 'It is stored encrypted.',
                'webhookId' => 'Add a webhook to the same app, at the address and with the events shown here once this is saved, and copy its ID. Saving with a placeholder first is fine: edit it then.',
            ],
        ],
        'paystack' => [
            'name' => 'Paystack',
            'fields' => ['secretKey' => 'Secret key'],
            'help' => [
                'secretKey' => 'From Settings > API Keys & Webhooks in your Paystack Dashboard. Test and live have separate keys. It is stored encrypted, and it also checks Paystack\'s webhooks: put the address shown here once this is saved as the webhook URL on the same page. Customers with no email on their account are asked for one: Paystack requires it.',
            ],
        ],
        'flutterwave' => [
            'name' => 'Flutterwave',
            'fields' => ['secretKey' => 'Secret key', 'secretHash' => 'Secret hash'],
            'help' => [
                'secretKey' => 'From Settings > API Keys in your Flutterwave Dashboard. Test and live have separate keys. It is stored encrypted. Customers with no email on their account are asked for one: Flutterwave requires it.',
                'secretHash' => 'A long random text you choose under Settings > Webhooks, with the address shown here once this is saved. Turn on webhook retries there. Chargeback webhooks stay off until you ask Flutterwave for them: until then, handle chargebacks on its dashboard.',
            ],
        ],
        'tap' => [
            'name' => 'Tap',
            'fields' => ['secretKey' => 'Secret key'],
            'help' => [
                'secretKey' => 'From the API keys in your Tap Dashboard. Test and live have separate keys. It is stored encrypted, and it also checks Tap\'s webhooks, so there is no webhook to add. Customers with no email on their account are asked for one: Tap requires it. Tap sends no dispute events: handle disputes on its dashboard, and refund here what you agree to give back.',
            ],
        ],
    ],
    'modes' => ['test' => 'Test', 'live' => 'Live'],
    'methods' => [
        'card' => 'Cards',
        'upi' => 'UPI',
        'netbanking' => 'Net banking',
        'wallet' => 'Wallets',
        'emi' => 'EMI',
        'paylater' => 'Pay later',
    ],
    'dispute_actions' => [
        'nothing' => 'Nothing until it is decided; take the amount off if it is lost',
        'hold' => 'Take the amount off the wallet; give it back if the dispute is won',
        'suspend' => 'Take the amount off and suspend the account',
    ],
    'top_up_description' => ':brand account credit',
    'wrong_mode_keys' => 'These are :keys keys, and this way to pay is set to :mode.',
    'gateway_unclear' => 'The gateway answered without what it should have said.',
    'gateway_refund_failed' => 'The gateway says this refund failed.',
    'payer_detail_missing' => 'The gateway needs an email for this payment, and there is none.',
    'refund_in_progress' => 'A refund of this payment is being sent. Look again in a moment before sending another.',
    'too_many' => 'You can switch on up to :max ways to pay.',
    'order_mismatch' => 'List every way to pay once, in the new order.',
    'keys_refused' => 'The gateway did not accept these details: :reason',
    'max_below_min' => 'The largest top-up cannot be smaller than the smallest.',
    'amount_shape' => 'An amount above zero, with at most :places decimal places.',
    'amount_min' => 'The smallest top-up is :min :currency.',
    'amount_max' => 'The largest top-up is :max :currency.',
    'none_offered' => 'There is no way to pay yet. Contact support to add credit.',
    'test_needs_test_mode' => 'Only an online gateway in test mode takes a test payment.',
    'currency_not_taken' => 'This gateway does not take your currency.',
    'gateway_down' => 'This way to pay is not answering. Try again, or pick another.',
    'cannot_confirm' => 'This payment cannot be confirmed: only a bank transfer, or a payment waiting for review, can.',
    'cannot_cancel' => 'Only a payment still open can be canceled.',
    'cannot_refund' => 'Only a payment that was received, through a way to pay still installed, can be refunded here.',
    'refund_too_much' => 'At most :left :currency of this payment is left to refund.',
    'refund_whole' => 'This gateway refunds only the whole amount left: :left :currency.',
    'refund_failed' => 'The gateway did not confirm the refund (:reason). Sending the same refund again refunds at most once.',
    'dispute_suspend_reason' => 'A payment was disputed (:payment).',
    'no_payment' => 'There is no such payment.',
    'no_gateway' => 'There is no such way to pay.',
    'signature' => 'This event could not be verified.',
    'live_to_enable' => 'Switch to live before enabling it: a payment in test mode moves no money.',
];
