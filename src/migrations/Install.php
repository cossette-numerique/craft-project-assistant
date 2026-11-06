<?php

namespace cossette\projectassistant\migrations;

use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    public const CONVERSATIONS = '{{%projectassistant_conversations}}';
    public const EVENTS = '{{%projectassistant_events}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::CONVERSATIONS)) {
            $this->createTable(self::CONVERSATIONS, [
                'id' => $this->primaryKey(),
                'userId' => $this->integer()->notNull(),
                'title' => $this->string(255)->notNull()->defaultValue(''),
                // Model-facing history in API wire format (thinking blocks included, replayed verbatim).
                'messages' => $this->longText(),
                // What the chat UI shows: user/assistant messages, tool steps, ticket cards.
                'transcript' => $this->longText(),
                // Note for the model, prepended to the next user message (e.g. "ticket X was created").
                'pendingNote' => $this->text(),
                'ticketDraft' => $this->text(),
                'ticketReference' => $this->string(255),
                'ticketUrl' => $this->string(1024),
                'ticketStatus' => $this->string(64),
                'ticketPayload' => $this->longText(),
                'inputTokens' => $this->bigInteger()->notNull()->defaultValue(0),
                'outputTokens' => $this->bigInteger()->notNull()->defaultValue(0),
                'cacheReadTokens' => $this->bigInteger()->notNull()->defaultValue(0),
                'cacheWriteTokens' => $this->bigInteger()->notNull()->defaultValue(0),
                'costUsd' => $this->decimal(12, 6)->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::CONVERSATIONS, ['userId', 'dateUpdated']);
            $this->addForeignKey(null, self::CONVERSATIONS, ['userId'], Table::USERS, ['id'], 'CASCADE');
        }

        if (!$this->db->tableExists(self::EVENTS)) {
            // Audit log + usage ledger: one row per model call, tool call, ticket or error.
            $this->createTable(self::EVENTS, [
                'id' => $this->primaryKey(),
                'conversationId' => $this->integer(),
                'userId' => $this->integer(),
                'type' => $this->string(32)->notNull(),
                'name' => $this->string(255),
                'detail' => $this->longText(),
                'model' => $this->string(64),
                'inputTokens' => $this->integer()->notNull()->defaultValue(0),
                'outputTokens' => $this->integer()->notNull()->defaultValue(0),
                'cacheReadTokens' => $this->integer()->notNull()->defaultValue(0),
                'cacheWriteTokens' => $this->integer()->notNull()->defaultValue(0),
                'costUsd' => $this->decimal(12, 6)->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::EVENTS, ['type', 'dateCreated']);
            $this->createIndex(null, self::EVENTS, ['userId', 'dateCreated']);
            $this->createIndex(null, self::EVENTS, ['conversationId']);
            $this->addForeignKey(null, self::EVENTS, ['conversationId'], self::CONVERSATIONS, ['id'], 'SET NULL');
            $this->addForeignKey(null, self::EVENTS, ['userId'], Table::USERS, ['id'], 'SET NULL');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::EVENTS);
        $this->dropTableIfExists(self::CONVERSATIONS);

        return true;
    }
}
