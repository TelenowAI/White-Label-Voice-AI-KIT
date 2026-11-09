<?php

/**
 * The audit log (App\Kit\Audit\Audit): each action in words, nested as the action is named (`staff.invited` is
 * actions.staff.invited); an action with no words here shows as it is.
 */
return [
    'actions' => [
        'agents' => [
            'transfers_allowed' => 'Allowed transfers to a person',
            'transfers_stopped' => 'Stopped transfers to a person',
        ],
        'campaign_links' => [
            'allowed' => 'Allowed campaign contacts from a link',
            'stopped' => 'Stopped campaign contacts from a link',
        ],
        'starters' => [
            'published' => 'Published a starter agent for every account',
            'changed' => 'Renamed or redescribed a starter agent',
            'withdrawn' => 'Stopped offering a starter agent',
            'offered' => 'Offered a starter agent again',
        ],
        'softphone' => [
            'allowed' => 'Allowed calls from the browser',
            'stopped' => 'Stopped calls from the browser',
        ],
        'own_voices' => [
            'allowed' => 'Allowed customers to clone voices',
            'stopped' => 'Stopped customers cloning voices',
            'limit_set' => 'Changed how many cloned voices an account may have',
            'created' => "Made a voice of the account's own, confirming the person's signed release",
            'sample_added' => "Added a recording to a voice of the account's own",
            'cloned' => "Cloned a voice of the account's own",
            'clone_removed' => "Removed a clone of a voice of the account's own",
            'deleted' => "Deleted a voice of the account's own",
        ],
        'consent' => [
            'required_for_all' => "Required consent for every customer's calls",
            'left_to_customers' => 'Left consent to each customer',
        ],
        'staff' => [
            'invited' => 'Invited to the team',
            'invite_revoked' => 'Cancelled a team invite',
            'joined' => 'Joined the team',
            'role_changed' => 'Changed a role',
            'removed' => 'Removed from the team',
        ],
        'customer' => [
            'suspended' => 'Suspended a customer',
            'resumed' => 'Resumed a customer',
            'credited' => 'Added credit',
            'plan_changed' => 'Changed the plan',
            'limits_changed' => 'Changed the limits',
            'closing' => 'Closed a customer',
            'viewed_as' => 'Looked at the console as a customer',
            'view_ended' => 'Stopped looking as a customer',
        ],
        'email' => [
            'template_saved' => "Changed an email's words",
            'template_reset' => "Put an email's words back to the original",
        ],
    ],
];
