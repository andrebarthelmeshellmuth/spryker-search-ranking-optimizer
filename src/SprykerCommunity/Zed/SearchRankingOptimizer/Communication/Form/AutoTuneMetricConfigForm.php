<?php

/**
 * This file is part of the spryker-community/search-ranking-optimizer package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchRankingOptimizer\Communication\Form;

use SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

/**
 * One row per metric on the Auto-Tune Settings page. `autoTuneThreshold` is deliberately NOT required —
 * a blank field is how a metric opts OUT of auto-tune entirely (see the transfer/schema's own
 * docblocks); `isAutoUpdateEnabled`/`isNotifyEnabled` only matter once a threshold is actually set (i.e.
 * the fit has actually dropped below it this run — a metric that still passes never reaches either flag,
 * checked or not).
 *
 * The two flags are independent, not a single on/off/proposal tri-state — all 4 combinations are real:
 * - Auto-update ON, Notify ON: refit is applied automatically, then emailed as a completed change (audit/FYI).
 * - Auto-update OFF, Notify ON: nothing is applied; emailed as a proposed candidate formula for a human to
 *   review and, if they agree, apply themselves by editing the formula on the Metrics page.
 * - Auto-update ON, Notify OFF: refit is applied automatically with no email at all — only discoverable
 *   via search-ranking's own Metric History page afterward.
 * - Auto-update OFF, Notify OFF: passive logging only (an isChange=false history row) — no action, no email.
 *
 * Every metric that crossed its own threshold this run, across every store/locale, is combined into ONE
 * summary email per run (see AutoTuneRunnerInterface::run()), each row correctly labeled applied/proposed
 * per its OWN isAutoUpdateEnabled even in a mixed batch. Recipients: every Zed user whose ACL group holds
 * the {@see \SprykerCommunity\Shared\SearchRankingOptimizer\SearchRankingOptimizerConfig::AUTO_TUNE_NOTIFICATION_ROLE_NAME}
 * role (see AutoTuneNotificationRecipientResolverInterface) — nobody role-less gets emailed, regardless of
 * how many metrics have Notify checked.
 */
class AutoTuneMetricConfigForm extends AbstractType
{
    /**
     * @var string
     */
    public const FIELD_ID_SEARCH_RANKING_METRIC = 'idSearchRankingMetric';

    /**
     * @var string
     */
    public const FIELD_AUTO_TUNE_THRESHOLD = 'autoTuneThreshold';

    /**
     * @var string
     */
    public const FIELD_IS_AUTO_UPDATE_ENABLED = 'isAutoUpdateEnabled';

    /**
     * @var string
     */
    public const FIELD_AUTO_UPDATE_SCOPE = 'autoUpdateScope';

    /**
     * @var string
     */
    public const FIELD_IS_NOTIFY_ENABLED = 'isNotifyEnabled';

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(static::FIELD_ID_SEARCH_RANKING_METRIC, HiddenType::class);

        $builder->add(static::FIELD_AUTO_TUNE_THRESHOLD, NumberType::class, [
            'label' => 'Auto-tune threshold (R²)',
            'help' => 'A floor, not a target: a refit is only attempted when the Current fit (R²) column '
                . 'is BELOW this. Set it at or below the current fit and the fit already "passes" — nothing '
                . 'will ever update.',
            'html5' => true,
            'scale' => 4,
            'required' => false,
            'attr' => [
                'placeholder' => (string)SearchRankingOptimizerConfig::getAutoTuneThresholdSuggestedDefault(),
            ],
            'constraints' => [
                new GreaterThan(0),
                new LessThanOrEqual(1),
            ],
        ]);

        $builder->add(static::FIELD_IS_AUTO_UPDATE_ENABLED, ChoiceType::class, [
            'label' => 'Auto-update',
            'help' => 'On: once the fit drops below the threshold, the refit is saved automatically — no '
                . 'review step. Off: the refit is only computed and reported (Notify below), never saved '
                . 'automatically; you apply it yourself on the Metrics page if you agree with it.',
            'choices' => ['Off' => false, 'On' => true],
        ]);

        $builder->add(static::FIELD_AUTO_UPDATE_SCOPE, ChoiceType::class, [
            'label' => 'Auto-update scope',
            'choices' => [
                'Keep current shape (parameters only)' => SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PARAMETERS_ONLY,
                "Program's choice (may switch shape)" => SearchRankingOptimizerConfig::AUTO_UPDATE_SCOPE_PROGRAM_CHOICE,
            ],
        ]);

        $builder->add(static::FIELD_IS_NOTIFY_ENABLED, ChoiceType::class, [
            'label' => 'Notify by email',
            'help' => 'On: once the fit drops below the threshold, a summary email goes to every Zed user '
                . 'whose ACL group holds the search-score-admin role (every eligible metric across every '
                . 'store/locale this run is combined into one email, not one per metric). What the email '
                . 'reports depends on Auto-update above, independently of this field: with Auto-update ON '
                . 'it reports a change that was ALREADY applied (FYI/audit — nothing left to decide); with '
                . 'Auto-update OFF it reports a PROPOSED formula that was NOT applied, for you to review and '
                . 'apply yourself if you agree. Auto-update ON + Notify OFF silently applies with no email at '
                . 'all — only visible afterward via Metric History.',
            'choices' => ['Off' => false, 'On' => true],
        ]);
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'search_ranking_optimizer_auto_tune_metric_config';
    }
}
