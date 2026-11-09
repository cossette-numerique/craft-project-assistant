<?php

namespace cossette\projectassistant\migrations;

use craft\db\Migration;

/**
 * Developer chat: conversation mode and tool calls waiting for approval.
 */
class m261109_000000_developer_chat extends Migration
{
    public function safeUp(): bool
    {
        $table = Install::CONVERSATIONS;
        if (!$this->db->columnExists($table, 'mode')) {
            $this->addColumn($table, 'mode', $this->string(16)->notNull()->defaultValue('client')->after('userId'));
        }
        if (!$this->db->columnExists($table, 'pendingTools')) {
            $this->addColumn($table, 'pendingTools', $this->longText()->after('pendingNote'));
        }
        $this->createIndex(null, $table, ['userId', 'mode', 'dateUpdated']);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261109_000000_developer_chat cannot be reverted.\n";

        return false;
    }
}
