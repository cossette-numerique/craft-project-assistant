<?php

namespace cossette\projectassistant\services;

use craft\db\Query;
use craft\helpers\Db;
use cossette\projectassistant\migrations\Install;
use cossette\projectassistant\Plugin;
use cossette\projectassistant\records\EventRecord;
use DateTime;
use yii\base\Component;

/**
 * Token usage, cost estimates and monthly budgets. Costs are estimates from
 * the price table in the settings; the provider's invoice is authoritative.
 */
class Usage extends Component
{
    /**
     * @param array{input_tokens?: int, output_tokens?: int, cache_read_input_tokens?: int, cache_creation_input_tokens?: int} $usage
     */
    public function cost(string $model, array $usage): float
    {
        $prices = Plugin::getInstance()->getSettings()->getPrices($model);
        if ($prices === null) {
            return 0.0;
        }

        return (
            ($usage['input_tokens'] ?? 0) * $prices['input']
            + ($usage['output_tokens'] ?? 0) * $prices['output']
            + ($usage['cache_read_input_tokens'] ?? 0) * $prices['cacheRead']
            + ($usage['cache_creation_input_tokens'] ?? 0) * $prices['cacheWrite']
        ) / 1_000_000;
    }

    public function monthStart(?DateTime $now = null): DateTime
    {
        $now ??= new DateTime();

        return new DateTime($now->format('Y-m-01 00:00:00'), $now->getTimezone());
    }

    public function spentThisMonth(?int $userId = null): float
    {
        $query = (new Query())
            ->from(Install::EVENTS)
            ->where(['type' => EventRecord::TYPE_API_CALL])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($this->monthStart())]);
        if ($userId !== null) {
            $query->andWhere(['userId' => $userId]);
        }

        return (float)$query->sum('costUsd');
    }

    /**
     * @return string|null why the user can't ask a question right now, or null if they can
     */
    public function budgetBlock(int $userId): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        if ($settings->monthlyBudget > 0 && $this->spentThisMonth() >= $settings->monthlyBudget) {
            return \Craft::t(Plugin::HANDLE, 'The monthly budget for the assistant has been reached. It will be available again next month, or an administrator can raise the budget.');
        }
        if ($settings->userMonthlyBudget > 0 && $this->spentThisMonth($userId) >= $settings->userMonthlyBudget) {
            return \Craft::t(Plugin::HANDLE, 'You have reached your monthly budget for the assistant. It will be available again next month, or an administrator can raise the budget.');
        }

        return null;
    }

    /**
     * Monthly totals per user, newest month first. Grouped per month in PHP
     * so it works on MySQL and PostgreSQL alike.
     *
     * @return array<string, array{costUsd: float, calls: int, questions: int, users: array}>
     */
    public function report(int $months = 6): array
    {
        $report = [];
        $start = $this->monthStart();
        for ($i = 0; $i < $months; $i++) {
            $from = (clone $start)->modify("-$i months");
            $to = (clone $from)->modify('+1 month');
            $range = ['and', ['>=', 'e.dateCreated', Db::prepareDateForDb($from)], ['<', 'e.dateCreated', Db::prepareDateForDb($to)]];

            $users = (new Query())
                ->select([
                    'e.userId',
                    'calls' => 'COUNT(*)',
                    'conversations' => 'COUNT(DISTINCT [[e.conversationId]])',
                    'inputTokens' => 'SUM([[e.inputTokens]])',
                    'outputTokens' => 'SUM([[e.outputTokens]])',
                    'cacheReadTokens' => 'SUM([[e.cacheReadTokens]])',
                    'cacheWriteTokens' => 'SUM([[e.cacheWriteTokens]])',
                    'costUsd' => 'SUM([[e.costUsd]])',
                ])
                ->from(['e' => Install::EVENTS])
                ->where(['e.type' => EventRecord::TYPE_API_CALL])
                ->andWhere($range)
                ->groupBy(['e.userId'])
                ->orderBy(['costUsd' => SORT_DESC])
                ->all();
            if (!$users) {
                continue;
            }
            $questions = (new Query())
                ->select(['e.userId', 'n' => 'COUNT(*)'])
                ->from(['e' => Install::EVENTS])
                ->where(['e.type' => EventRecord::TYPE_QUESTION])
                ->andWhere($range)
                ->groupBy(['e.userId'])
                ->pairs();
            foreach ($users as &$row) {
                $row['questions'] = (int)($questions[$row['userId']] ?? 0);
                $row['user'] = $row['userId'] ? \Craft::$app->getUsers()->getUserById((int)$row['userId']) : null;
            }
            unset($row);

            $report[$from->format('Y-m')] = [
                'costUsd' => array_sum(array_map(fn($r) => (float)$r['costUsd'], $users)),
                'calls' => array_sum(array_map(fn($r) => (int)$r['calls'], $users)),
                'questions' => array_sum(array_column($users, 'questions')),
                'users' => $users,
            ];
        }

        return $report;
    }
}
