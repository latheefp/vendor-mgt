<?php
declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * What a charge line represents.
 *
 * The set is deliberately fine-grained: an invoice that shows "Rs.575"
 * invites an argument, whereas one that shows "Rs.500 service 45-85in,
 * Rs.75 closed within 48h" does not.
 */
enum ChargeLineType: string
{
    /** The rate card amount for the job itself. */
    case Base = 'base';

    /** An SLA incentive earned, e.g. +Rs.50 for closing inside 24h. */
    case SlaBonus = 'sla_bonus';

    /** An SLA deduction incurred, e.g. -Rs.50 for installing after 48h. */
    case SlaPenalty = 'sla_penalty';

    /** Distance beyond the free radius, at the agreed per-km rate. */
    case Travel = 'travel';

    /** Cost of a spare part consumed on the job. */
    case SpareCost = 'spare_cost';

    /** Our margin on a spare billed to the customer. */
    case SpareMargin = 'spare_margin';

    /** The company's cut of out-of-warranty service we collected. */
    case CompanyRoyalty = 'company_royalty';

    /** What the technician earns for the job. */
    case TechnicianPayout = 'technician_payout';

    /** The technician's share of an SLA bonus. */
    case TechnicianBonusShare = 'technician_bonus_share';

    /** An SLA penalty recovered from the technician who caused it. */
    case TechnicianPenaltyRecovery = 'technician_penalty_recovery';

    /**
     * Work agreed on the job itself, recorded while the ticket is still
     * open — a gas refill, a second visit, an inspection fee.
     *
     * Distinct from Adjustment: this is not a correction to a bill already
     * sent, it is part of the original bill being assembled. Closure keeps
     * these lines rather than recomputing them away, because the desk
     * agreed them with the customer and the rate card never knew about
     * them. See TicketClosureService::freeze().
     */
    case Boq = 'boq';

    /**
     * A correction. Frozen lines are never edited, so every after-the-fact
     * change to a ticket's money is an adjustment row with a reason.
     */
    case Adjustment = 'adjustment';

    /**
     * A technician cost the rate card never priced — a lump sum, an
     * extra service charge, bata/transport — recorded against a named
     * `technician_expense_type` rather than free-form like Adjustment.
     * Lands on technician_payable with no matching receivable/collection
     * line, so it comes straight out of margin, same as paying it by hand
     * out of the till. See TicketAdjustmentService::addTechnicianExpense().
     */
    case TechnicianExpense = 'technician_expense';

    public function label(): string
    {
        return match ($this) {
            self::Base => 'Service charge',
            self::SlaBonus => 'SLA incentive',
            self::SlaPenalty => 'SLA deduction',
            self::Travel => 'Travel reimbursement',
            self::SpareCost => 'Spare part cost',
            self::SpareMargin => 'Spare part margin',
            self::CompanyRoyalty => 'Company royalty',
            self::TechnicianPayout => 'Technician payout',
            self::TechnicianBonusShare => 'Technician bonus share',
            self::TechnicianPenaltyRecovery => 'Penalty recovery',
            self::Boq => 'Additional service',
            self::Adjustment => 'Adjustment',
            self::TechnicianExpense => 'Technician expense',
        };
    }
}
