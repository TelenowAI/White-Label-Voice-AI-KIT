<?php

// Starter agents (App\Kit\Agents\StarterAgents, Admin\StartersController, Console\StartersController). Staff read the first few; customers only `not_found`.
return [
    'fields' => [
        'agentId' => 'Agent',
        'name' => 'Name',
        'description' => 'Description',
        'reviewed' => 'Reviewed',
        'offered' => 'Offered',
    ],
    'agent_not_yours' => 'Choose an agent in an account you own: a starter is offered to every account.',
    'review_first' => 'Read what it carries first, and say you have.',
    'not_found' => 'There is no such starter agent.',
];
