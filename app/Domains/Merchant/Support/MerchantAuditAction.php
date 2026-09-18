<?php

namespace App\Domains\Merchant\Support;

/**
 * The fixed catalog of action names MerchantAuditLog entries are written
 * with — a backed enum rather than a free string (contrast
 * App\Domains\Platform\Models\AuditLog's `action`) so every call site
 * shares exactly one spelling per action and a typo fails at compile time
 * rather than silently fragmenting the trail into two near-identical
 * strings.
 */
enum MerchantAuditAction: string
{
    case OrderCheckedOut = 'order.checked_out';
    case OrderCompleted = 'order.completed';
    case OrderVoided = 'order.voided';

    case CashSessionOpened = 'cash_session.opened';
    case CashSessionClosed = 'cash_session.closed';
    case CashMovementRecorded = 'cash_session.movement_recorded';
    case RemittanceCreated = 'remittance.created';
    case RemittanceConfirmed = 'remittance.confirmed';

    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductDeleted = 'product.deleted';
    case RecipeUpdated = 'recipe.updated';

    case IngredientCreated = 'ingredient.created';
    case IngredientUpdated = 'ingredient.updated';
    case IngredientDeleted = 'ingredient.deleted';

    case TeamMemberAdded = 'team.member_added';
    case TeamMemberRoleUpdated = 'team.member_role_updated';
    case TeamMemberRemoved = 'team.member_removed';

    case ProfileUpdated = 'profile.updated';
}
