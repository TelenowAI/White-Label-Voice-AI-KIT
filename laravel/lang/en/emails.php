<?php

/**
 * The kit's emails and how it sends them (App\Kit\Email): each kind's texts (subject, lines, its button, the lines
 * after it), each driver's form and label, and the words Admin → Email and the wizard's Email step answer with.
 * `:brand` is always the operator's brand name.
 */
return [
    'kinds' => [
        'test' => [
            'subject' => 'Email from :brand works',
            'lines' => ['This is a test sent by :brand to check that its email works. There is nothing to do.'],
        ],
        'team_invite' => [
            'subject' => 'Join :account on :brand',
            'lines' => ['You are invited to join :account on :brand.'],
            'action' => 'Accept the invite',
            'after' => ['The link works until :expires. If you were not expecting this, you can ignore this email.'],
        ],
        'staff_invite' => [
            'subject' => 'Join the :brand team',
            'lines' => ['You are invited to help run :brand, as :role.'],
            'action' => 'Accept the invite',
            'after' => ['The link works until :expires. If you were not expecting this, you can ignore this email.'],
        ],
        'password_reset' => [
            'subject' => 'Reset your :brand password',
            'lines' => ['Someone asked to reset the password of the :brand account for :email.'],
            'action' => 'Choose a new password',
            'after' => ['The link works once, for :minutes minutes. If it was not you, ignore this email: your password stays as it is.'],
        ],
        'signup_verify' => [
            'subject' => 'Confirm your email for :brand',
            'lines' => ['Hi :name, thanks for signing up to :brand. Confirm this is your address to finish.'],
            'action' => 'Confirm my email',
            'after' => ['The link works for :hours hours. If you did not sign up, ignore this email: nothing is made.'],
        ],
        'signup_exists' => [
            'subject' => 'You already have a :brand account',
            'lines' => ['Someone just tried to sign up to :brand with this address, which already has an account.'],
            'action' => 'Sign in',
            'after' => ['Forgot your password? Choose "Forgot your password?" when you sign in. If it was not you, ignore this email.'],
        ],
        'signup_approved' => [
            'subject' => 'Your :brand account is ready',
            'lines' => ['Hi :name, your sign-up to :brand is approved and your account is ready.'],
            'action' => 'Sign in',
        ],
        'signup_rejected' => [
            'subject' => 'Your :brand sign-up',
            'lines' => ['Hi :name, your sign-up to :brand was not approved. If you think this is a mistake, write to us.'],
        ],
        'sign_in_link' => [
            'subject' => 'Your :brand sign-in link',
            'lines' => ['Use this link to sign in to :brand.'],
            'action' => 'Sign in',
            'after' => ['It works once, for :minutes minutes. If you did not ask for it, ignore this email.'],
        ],
        'sign_in_code' => [
            'subject' => 'Your :brand sign-in code',
            'lines' => ['Your code to sign in to :brand is :code'],
            'after' => ['It works once, for :minutes minutes. Never share it. If you did not ask for it, ignore this email.'],
        ],
        'signup_code' => [
            'subject' => 'Your :brand sign-up code',
            'lines' => ['Hi :name, thanks for signing up to :brand. Your code to finish is :code'],
            'after' => ['It works once, for :minutes minutes. Never share it. If you did not sign up, ignore this email: nothing is made.'],
        ],
        'call_summary' => [
            'subject' => ':agent: a call with :caller',
            'lines' => ['Hi :name, :agent had a call with :caller on :when. It lasted :duration.:outcome', ':summary'],
            'action' => 'Open the call',
        ],
        'payment_received' => [
            'subject' => 'Payment received: :amount',
            'lines' => ['Hi :name, we received your payment of :amount for :account. It is in your wallet now.'],
            'action' => 'Open your wallet',
        ],
        'balance_low' => [
            'subject' => 'Your :brand balance is low: :balance',
            'lines' => ['Hi :name, the balance of :account is down to :balance, at or below its warning level of :level.', 'Add credit before it runs out: calls stop when it reaches zero.'],
            'action' => 'Add credit',
            'after' => ['You can change when this email comes on your wallet page.'],
        ],
        'balance_empty' => [
            'subject' => 'Your :brand calls have stopped: no credit left',
            'lines' => ['Hi :name, :account has run out of credit (its balance is :balance), so its calls have stopped.', 'Add credit and they start again.'],
            'action' => 'Add credit',
        ],
        'platform_balance_low' => [
            'subject' => ':brand: the voice platform balance is low',
            'lines' => ['Hi :name, the balance of your account at the voice platform is down to :balance, at or below your warning level of :level.',
                'Add credit there before it runs out: when it does, every customer\'s calls stop and numbers are not renewed.'],
            'action' => 'Open platform billing',
        ],
        'platform_stopped' => [
            'subject' => ':brand: calls have stopped at the voice platform',
            'lines' => ['Hi :name, the voice platform has stopped your account (:reason), so no customer can start a call.',
                'Customers see that the service is paused until it is fixed.'],
            'action' => 'Open platform billing',
        ],
        'number_rent_short' => [
            'subject' => "Your :brand balance won't cover your numbers' rent",
            'lines' => ["Hi :name, the balance of :account (:balance) won't cover the next rent of :numbers, due from :date. It needs :short more.",
                'The rent is charged all the same, and calls stop when the balance reaches zero.'],
            'action' => 'Add credit',
        ],
    ],
    // What Admin → Email calls each kind in its list.
    'kind_names' => [
        'test' => 'Test',
        'team_invite' => 'Team invite',
        'staff_invite' => 'Staff invite',
        'password_reset' => 'Password reset',
        'signup_verify' => 'Sign-up: confirm email',
        'signup_exists' => 'Sign-up: already has an account',
        'signup_approved' => 'Sign-up approved',
        'signup_rejected' => 'Sign-up refused',
        'sign_in_link' => 'Sign-in link',
        'sign_in_code' => 'Sign-in code',
        'signup_code' => 'Sign-up: code',
        'call_summary' => 'After a call',
        'payment_received' => 'Payment received',
        'balance_low' => 'Low balance',
        'balance_empty' => 'Out of credit',
        'platform_balance_low' => 'Voice platform: balance low',
        'platform_stopped' => 'Voice platform: calls stopped',
        'number_rent_short' => "Numbers' rent not covered",
    ],
    // What each placeholder an email can use stands for (Admin → Email → Templates); one with no words here shows as
    // it is.
    'placeholders' => [
        'brand' => 'Your brand name',
        'name' => "The person's name",
        'account' => "The customer account's name",
        'email' => "The person's email address",
        'role' => 'The team role offered',
        'expires' => 'When the link stops working',
        'minutes' => 'How many minutes the link or code works',
        'code' => 'The one-time code',
        'hours' => 'How many hours the link works',
        'amount' => 'The amount paid, with its currency',
        'balance' => 'The balance now, with its currency',
        'level' => 'The balance the account is warned at, with its currency',
        'agent' => "The agent's name",
        'caller' => 'The number the call was with',
        'when' => 'When the call started',
        'duration' => 'How long the call lasted',
        'outcome' => 'How the call ended, as a short sentence, or nothing when that is not known',
        'summary' => "The call's summary",
        'numbers' => 'The phone numbers it is about',
        'date' => 'The first day one of them is due',
        'short' => 'How much more the balance needs, with its currency',
        'reason' => 'Why the voice platform stopped the account',
    ],
    'call_duration' => ':minutes min :seconds s',
    'call_no_number' => 'an unknown number',
    'call_no_summary' => 'There is no summary of this call.',
    'call_outcome' => ' Outcome: :outcome.',
    'call_outcomes' => ['answered' => 'answered', 'no-answer' => 'no answer', 'completed' => 'answered', 'no_answer' => 'no answer', 'busy' => 'busy', 'failed' => 'failed'],
    // Admin → Email → Templates: what a template may hold (App\Kit\Email\EmailTexts).
    'template' => [
        'unknown_kind' => 'There is no such email.',
        'subject_required' => 'Give the email a subject, on one line, of up to :max characters.',
        'lines_required' => 'Write at least one paragraph, up to :max, each of up to :length characters.',
        'after_shape' => 'Up to :max paragraphs after the button, each of up to :length characters.',
        'action_required' => 'Give the button its words, of up to :max characters.',
        'no_action' => 'This email has no button.',
        'unknown_placeholder' => 'This email cannot fill :placeholders. It can use: :known.',
    ],
    'support' => 'Questions? Write to :email.',

    'drivers' => [
        'smtp' => [
            'name' => 'SMTP server',
            'label' => ':host, port :port (:encryption)',
            'fields' => [
                'host' => 'Server',
                'port' => 'Port',
                'encryption' => 'Encryption',
                'username' => 'Username',
                'password' => 'Password',
            ],
            'help' => [
                'encryption' => 'STARTTLS is the usual choice on port 587; TLS on port 465. Choose none only for a relay on this machine.',
            ],
            'encryptions' => [
                'tls' => 'STARTTLS',
                'ssl' => 'TLS',
                'none' => 'None',
            ],
        ],
    ],

    'fields' => [
        'driver' => 'Send with',
        'siteUrl' => 'Site address',
        'fromAddress' => 'From address',
        'fromName' => 'From name',
        'order' => 'Order',
    ],
    'help' => [
        'siteUrl' => 'Links in emails open this address.',
        'fromName' => 'Left empty: your brand name.',
    ],
    'no_sender' => 'No sender is set up.',
    'test_failed' => 'The test email could not be sent: :reason',
    'no_from' => 'Set who emails come from first.',
    'too_many' => 'Up to :max senders.',
    'unknown_sender' => 'There is no such sender.',
    'order_mismatch' => 'List every sender once.',
];
