<?php

namespace App\Enums;

/**
 * Audit log event names, stored in audit_logs.event.
 *
 * Stored rows keep these values forever, so a value must never be renamed
 * or removed once released; add a new case instead.
 */
enum AuditEvent: string
{
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserActivated = 'user.activated';
    case UserDeactivated = 'user.deactivated';
    case UserPasswordReset = 'user.password_reset';

    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductStatusChanged = 'product.status_changed';
    case ProductDeleted = 'product.deleted';
    case ProductAvailabilityChanged = 'product.availability_changed';

    case ProductImageAdded = 'product_image.added';
    case ProductImageUpdated = 'product_image.updated';
    case ProductImageReordered = 'product_image.reordered';
    case ProductImageDeleted = 'product_image.deleted';

    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryStatusChanged = 'category.status_changed';
    case CategoryDeleted = 'category.deleted';

    case BrandCreated = 'brand.created';
    case BrandUpdated = 'brand.updated';
    case BrandStatusChanged = 'brand.status_changed';
    case BrandDeleted = 'brand.deleted';

    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';
    case CustomerBlocked = 'customer.blocked';
    case CustomerUnblocked = 'customer.unblocked';
    case CustomerDeleted = 'customer.deleted';

    case StockAdjusted = 'stock.adjusted';

    case OrderCreated = 'order.created';
    case OrderUpdated = 'order.updated';
    case OrderStatusChanged = 'order.status_changed';
    case OrderCancelled = 'order.cancelled';
    case OrderReturned = 'order.returned';

    case PaymentRecorded = 'payment.recorded';
    case PaymentRefunded = 'payment.refunded';

    case CourierBookingRequested = 'courier.booking_requested';
    case CourierBookingSucceeded = 'courier.booking_succeeded';
    case CourierBookingFailed = 'courier.booking_failed';
    case CourierStatusChanged = 'courier.status_changed';

    case AuthLoginSucceeded = 'auth.login_succeeded';
    case AuthLoginFailed = 'auth.login_failed';
    case AuthLoginLocked = 'auth.login_locked';
    case AuthLogout = 'auth.logout';
    case AuthDeactivatedLoginAttempt = 'auth.deactivated_login_attempt';

    public function label(): string
    {
        return match ($this) {
            self::UserCreated => 'User created',
            self::UserUpdated => 'User updated',
            self::UserRoleChanged => 'User role changed',
            self::UserActivated => 'User activated',
            self::UserDeactivated => 'User deactivated',
            self::UserPasswordReset => 'User password reset',
            self::ProductCreated => 'Product created',
            self::ProductUpdated => 'Product updated',
            self::ProductStatusChanged => 'Product status changed',
            self::ProductDeleted => 'Product deleted',
            self::ProductAvailabilityChanged => 'Product availability changed',
            self::ProductImageAdded => 'Product image added',
            self::ProductImageUpdated => 'Product image updated',
            self::ProductImageReordered => 'Product image reordered',
            self::ProductImageDeleted => 'Product image deleted',
            self::CategoryCreated => 'Category created',
            self::CategoryUpdated => 'Category updated',
            self::CategoryStatusChanged => 'Category status changed',
            self::CategoryDeleted => 'Category deleted',
            self::BrandCreated => 'Brand created',
            self::BrandUpdated => 'Brand updated',
            self::BrandStatusChanged => 'Brand status changed',
            self::BrandDeleted => 'Brand deleted',
            self::CustomerCreated => 'Customer created',
            self::CustomerUpdated => 'Customer updated',
            self::CustomerBlocked => 'Customer blocked',
            self::CustomerUnblocked => 'Customer unblocked',
            self::CustomerDeleted => 'Customer deleted',
            self::StockAdjusted => 'Stock adjusted',
            self::OrderCreated => 'Order created',
            self::OrderUpdated => 'Order details updated',
            self::OrderStatusChanged => 'Order status changed',
            self::OrderCancelled => 'Order cancelled',
            self::OrderReturned => 'Order returned',
            self::PaymentRecorded => 'Payment recorded',
            self::PaymentRefunded => 'Refund recorded',
            self::CourierBookingRequested => 'Courier booking requested',
            self::CourierBookingSucceeded => 'Courier booking succeeded',
            self::CourierBookingFailed => 'Courier booking failed',
            self::CourierStatusChanged => 'Courier status changed',
            self::AuthLoginSucceeded => 'Signed in',
            self::AuthLoginFailed => 'Failed sign-in attempt',
            self::AuthLoginLocked => 'Sign-in locked after too many attempts',
            self::AuthLogout => 'Signed out',
            self::AuthDeactivatedLoginAttempt => 'Sign-in attempt by a deactivated account',
        };
    }
}
