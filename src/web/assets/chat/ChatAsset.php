<?php

namespace cossette\projectassistant\web\assets\chat;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ChatAsset extends AssetBundle
{
    /** UI strings the JS needs (Craft.t). */
    public const TRANSLATIONS = [
        'Ask a question about the website…',
        'Send',
        'Create a ticket',
        'Thinking…',
        'Steps',
        'This question cost about {cost}.',
        'Something went wrong. The error was logged.',
        'Ticket draft for the dev team',
        'Review and edit before sending. Nothing is sent until you confirm.',
        'Title',
        'Category',
        'Priority',
        'What you need',
        'What the assistant found',
        'What was checked',
        'Suggested approach',
        'Anything to add? (optional)',
        'Send ticket',
        'Dismiss',
        'Draft dismissed.',
        'Ticket sent',
        'Ticket saved in the CMS',
        'The ticket system isn’t connected yet, so the dev team was not notified automatically. Your request is saved with this conversation.',
        'Reference',
        'Open ticket',
        'Feature request',
        'Bug',
        'Configuration change',
        'Help with content',
        'Question',
        'Low',
        'Normal',
        'High',
        'Urgent',
        'Sending…',
        'You asked for a ticket',
        'The connection was interrupted. Reload the page to see the latest state.',
        'Change waiting for approval',
        'Changes existing data',
        'Arguments',
        'Result',
        'Approve',
        'Reject',
        'Approved and run',
        'Approved, but it failed',
        'Rejected: not run',
        'Waiting for your approval. Sending a new message instead rejects the pending changes.',
    ];

    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['chat.js'];
        $this->css = ['chat.css'];

        parent::init();
    }
}
