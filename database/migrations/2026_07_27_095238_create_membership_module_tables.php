<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('membership_categories')) {
            Schema::create('membership_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->text('description')->nullable();
                $table->boolean('status')->default(true)->index();
                $table->unsignedSmallInteger('sort_order')->default(0)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('membership_features')) {
            Schema::create('membership_features', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->text('description')->nullable();
                $table->string('feature_type', 30)->default('boolean')->index();
                // boolean, number, text, limit
                $table->boolean('status')->default(true)->index();
                $table->unsignedSmallInteger('sort_order')->default(0)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('membership_plans')) {
            Schema::create('membership_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')
                    ->constrained('membership_categories')
                    ->cascadeOnDelete();

                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->text('short_description')->nullable();
                $table->longText('description')->nullable();

                $table->string('currency', 10)->default('INR');
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('sale_price', 12, 2)->nullable();

                $table->unsignedSmallInteger('duration')->default(1);
                $table->string('duration_type', 20)->default('months');
                // days, months, years

                $table->unsignedSmallInteger('trial_days')->default(0);
                $table->boolean('is_popular')->default(false)->index();
                $table->boolean('status')->default(true)->index();
                $table->unsignedSmallInteger('sort_order')->default(0)->index();
                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['category_id', 'status']);
                $table->index(['price', 'sale_price']);
            });
        }

        if (!Schema::hasTable('membership_plan_features')) {
            Schema::create('membership_plan_features', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')
                    ->constrained('membership_plans')
                    ->cascadeOnDelete();

                $table->foreignId('feature_id')
                    ->constrained('membership_features')
                    ->cascadeOnDelete();

                $table->string('feature_value', 500);
                $table->boolean('is_unlimited')->default(false);
                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->unique(['plan_id', 'feature_id']);
                $table->index('feature_id');
            });
        }

        if (!Schema::hasTable('membership_plan_role_rules')) {
            Schema::create('membership_plan_role_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')
                    ->constrained('membership_plans')
                    ->cascadeOnDelete();

                $table->foreignId('role_id')
                    ->constrained('roles')
                    ->cascadeOnDelete();

                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->unique(['plan_id', 'role_id']);
                $table->index(['role_id', 'is_active']);
            });
        }

        if (!Schema::hasTable('membership_coupons')) {
            Schema::create('membership_coupons', function (Blueprint $table) {
                $table->id();
                $table->string('code', 80)->unique();
                $table->string('title', 150);
                $table->text('description')->nullable();

                $table->string('discount_type', 30);
                // fixed, percentage
                $table->decimal('discount_value', 12, 2);

                $table->decimal('minimum_order_amount', 12, 2)->default(0);
                $table->decimal('maximum_discount_amount', 12, 2)->nullable();

                $table->timestamp('start_at')->nullable()->index();
                $table->timestamp('end_at')->nullable()->index();

                $table->unsignedInteger('usage_limit')->nullable();
                $table->unsignedInteger('usage_limit_per_user')->default(1);
                $table->unsignedInteger('used_count')->default(0);

                $table->longText('allowed_plan_ids')->nullable();
                $table->longText('allowed_category_ids')->nullable();

                $table->boolean('new_user_only')->default(false);
                $table->boolean('status')->default(true)->index();
                $table->timestamps();

                $table->index(['status', 'end_at']);
            });
        }

        if (!Schema::hasTable('membership_orders')) {
            Schema::create('membership_orders', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('plan_id')
                    ->constrained('membership_plans')
                    ->restrictOnDelete();

                $table->foreignId('coupon_id')
                    ->nullable()
                    ->constrained('membership_coupons')
                    ->nullOnDelete();

                $table->string('order_number', 80)->unique();

                $table->string('gateway_name', 50)->default('razorpay');
                $table->string('razorpay_order_id', 120)->nullable()->unique();

                $table->string('currency', 10)->default('INR');
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('taxable_amount', 12, 2)->default(0);
                $table->decimal('gst_percentage', 5, 2)->default(18);
                $table->decimal('gst_amount', 12, 2)->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);

                $table->string('payment_status', 30)->default('pending')->index();
                // pending, paid, failed, refunded, partially_refunded

                $table->string('order_status', 30)->default('pending')->index();
                // pending, processing, completed, cancelled, expired

                $table->string('payment_method', 50)->nullable();

                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('paid_at')->nullable()->index();
                $table->timestamp('cancelled_at')->nullable();

                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->longText('notes')->nullable();
                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'payment_status']);
                $table->index(['user_id', 'order_status']);
                $table->index(['plan_id', 'payment_status']);
                $table->index('created_at');
            });
        }

        if (!Schema::hasTable('user_memberships')) {
            Schema::create('user_memberships', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('plan_id')
                    ->constrained('membership_plans')
                    ->restrictOnDelete();

                $table->foreignId('order_id')
                    ->nullable()
                    ->unique()
                    ->constrained('membership_orders')
                    ->nullOnDelete();

                $table->foreignId('parent_membership_id')
                    ->nullable()
                    ->constrained('user_memberships')
                    ->nullOnDelete();

                $table->timestamp('start_date')->nullable()->index();
                $table->timestamp('expiry_date')->nullable()->index();

                $table->string('status', 30)->default('pending')->index();
                // pending, active, expired, cancelled, upgraded, downgraded, refunded, grace_period

                $table->boolean('auto_renew')->default(false);
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->timestamp('grace_until')->nullable();

                $table->string('source', 50)->default('purchase');
                // purchase, manual, renewal, upgrade, downgrade

                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['user_id', 'expiry_date']);
                $table->index(['status', 'expiry_date']);
                $table->index('plan_id');
            });
        }

        if (!Schema::hasTable('membership_addons')) {
            Schema::create('membership_addons', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->text('description')->nullable();

                $table->string('addon_type', 50)->index();
                // boost, featured_listing, homepage_banner, photography, virtual_tour, ai_enhancement

                $table->string('currency', 10)->default('INR');
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('sale_price', 12, 2)->nullable();

                $table->string('credit_type', 50)->nullable()->index();
                $table->unsignedInteger('credit_quantity')->nullable();

                $table->unsignedSmallInteger('duration_days')->nullable();
                $table->boolean('status')->default(true)->index();
                $table->unsignedSmallInteger('sort_order')->default(0)->index();
                $table->longText('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('membership_addon_orders')) {
            Schema::create('membership_addon_orders', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('addon_id')
                    ->constrained('membership_addons')
                    ->restrictOnDelete();

                $table->foreignId('membership_id')
                    ->nullable()
                    ->constrained('user_memberships')
                    ->nullOnDelete();

                $table->foreignId('coupon_id')
                    ->nullable()
                    ->constrained('membership_coupons')
                    ->nullOnDelete();

                $table->string('order_number', 80)->unique();

                $table->string('gateway_name', 50)->default('razorpay');
                $table->string('razorpay_order_id', 120)->nullable()->unique();

                $table->string('currency', 10)->default('INR');
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('taxable_amount', 12, 2)->default(0);
                $table->decimal('gst_percentage', 5, 2)->default(18);
                $table->decimal('gst_amount', 12, 2)->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);

                $table->string('payment_status', 30)->default('pending')->index();
                $table->string('order_status', 30)->default('pending')->index();
                $table->string('payment_method', 50)->nullable();

                $table->timestamp('paid_at')->nullable()->index();
                $table->timestamp('cancelled_at')->nullable();

                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'payment_status']);
                $table->index(['addon_id', 'payment_status']);
                $table->index(['membership_id', 'payment_status']);
            });
        }

        if (!Schema::hasTable('membership_payments')) {
            Schema::create('membership_payments', function (Blueprint $table) {
                $table->id();

                $table->foreignId('membership_order_id')
                    ->nullable()
                    ->constrained('membership_orders')
                    ->cascadeOnDelete();

                $table->foreignId('addon_order_id')
                    ->nullable()
                    ->constrained('membership_addon_orders')
                    ->cascadeOnDelete();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->string('payment_gateway', 50)->default('razorpay');

                $table->string('razorpay_order_id', 120)->nullable()->index();
                $table->string('razorpay_payment_id', 120)->nullable()->unique();
                $table->string('razorpay_signature', 255)->nullable();

                $table->string('currency', 10)->default('INR');
                $table->decimal('amount', 12, 2)->default(0);

                $table->string('payment_status', 30)->default('created')->index();
                // created, authorized, captured, failed, refunded

                $table->string('payment_method', 50)->nullable();
                $table->timestamp('payment_date')->nullable()->index();
                $table->timestamp('verified_at')->nullable();
                $table->text('failure_reason')->nullable();

                $table->longText('gateway_response')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'payment_status']);
                $table->index(['membership_order_id', 'payment_status']);
                $table->index(['addon_order_id', 'payment_status']);
            });
        }

        if (!Schema::hasTable('membership_webhook_events')) {
            Schema::create('membership_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('gateway', 50)->default('razorpay')->index();
                $table->string('event_id', 150)->unique();
                $table->string('event_name', 150)->index();
                $table->string('status', 30)->default('pending')->index();
                // pending, processed, failed, duplicate
                $table->longText('payload')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('processed_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('membership_refunds')) {
            Schema::create('membership_refunds', function (Blueprint $table) {
                $table->id();

                $table->foreignId('membership_order_id')
                    ->nullable()
                    ->constrained('membership_orders')
                    ->nullOnDelete();

                $table->foreignId('addon_order_id')
                    ->nullable()
                    ->constrained('membership_addon_orders')
                    ->nullOnDelete();

                $table->foreignId('payment_id')
                    ->nullable()
                    ->constrained('membership_payments')
                    ->nullOnDelete();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->string('refund_number', 80)->unique();
                $table->string('payment_gateway', 50)->default('razorpay');
                $table->string('gateway_refund_id', 150)->nullable()->unique();

                $table->string('currency', 10)->default('INR');
                $table->decimal('refund_amount', 12, 2);

                $table->string('refund_status', 30)->default('pending')->index();
                // pending, processed, failed

                $table->text('refund_reason')->nullable();

                $table->foreignId('processed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('processed_at')->nullable()->index();
                $table->longText('gateway_response')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'refund_status']);
            });
        }

        if (!Schema::hasTable('membership_coupon_usages')) {
            Schema::create('membership_coupon_usages', function (Blueprint $table) {
                $table->id();

                $table->foreignId('coupon_id')
                    ->constrained('membership_coupons')
                    ->cascadeOnDelete();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('membership_order_id')
                    ->nullable()
                    ->constrained('membership_orders')
                    ->nullOnDelete();

                $table->foreignId('addon_order_id')
                    ->nullable()
                    ->constrained('membership_addon_orders')
                    ->nullOnDelete();

                $table->timestamp('used_at')->useCurrent();

                $table->index(['coupon_id', 'user_id']);
                $table->index(['user_id', 'used_at']);
            });
        }

        if (!Schema::hasTable('membership_credit_balances')) {
            Schema::create('membership_credit_balances', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('membership_id')
                    ->constrained('user_memberships')
                    ->cascadeOnDelete();

                $table->string('credit_type', 50);
                // listing, featured_listing, boost, lead_view, video_upload, virtual_tour, ai_description

                $table->boolean('is_unlimited')->default(false);
                $table->unsignedInteger('total_credits')->nullable();
                $table->unsignedInteger('used_credits')->default(0);
                $table->unsignedInteger('remaining_credits')->nullable();

                $table->boolean('status')->default(true)->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['membership_id', 'credit_type']);
                $table->index(['user_id', 'credit_type']);
                $table->index(['membership_id', 'status']);
            });
        }

        if (!Schema::hasTable('membership_credit_transactions')) {
            Schema::create('membership_credit_transactions', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('membership_id')
                    ->nullable()
                    ->constrained('user_memberships')
                    ->nullOnDelete();

                $table->foreignId('balance_id')
                    ->nullable()
                    ->constrained('membership_credit_balances')
                    ->nullOnDelete();

                $table->string('credit_type', 50)->index();

                $table->string('transaction_type', 30)->index();
                // credit, debit, adjust, refund, expire

                $table->unsignedInteger('quantity')->default(1);
                $table->integer('balance_before')->nullable();
                $table->integer('balance_after')->nullable();

                $table->string('reference_type', 100)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();

                $table->string('reason', 500)->nullable();

                $table->foreignId('performed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['membership_id', 'credit_type', 'created_at'], 'mct_membership_credit_created_idx');
                $table->index(['reference_type', 'reference_id'], 'mct_reference_idx');
                $table->index(['user_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('membership_lead_unlocks')) {
            Schema::create('membership_lead_unlocks', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('membership_id')
                    ->nullable()
                    ->constrained('user_memberships')
                    ->nullOnDelete();

                $table->string('lead_reference_type', 100);
                $table->unsignedBigInteger('lead_reference_id');

                $table->timestamp('unlocked_at')->useCurrent();
                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->unique(
                    ['user_id', 'lead_reference_type', 'lead_reference_id'],
                    'mlu_user_lead_unique'
                );

                $table->index(['membership_id', 'unlocked_at']);
            });
        }

        if (!Schema::hasTable('membership_addon_usages')) {
            Schema::create('membership_addon_usages', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('addon_id')
                    ->constrained('membership_addons')
                    ->cascadeOnDelete();

                $table->foreignId('addon_order_id')
                    ->nullable()
                    ->constrained('membership_addon_orders')
                    ->nullOnDelete();

                $table->foreignId('membership_id')
                    ->nullable()
                    ->constrained('user_memberships')
                    ->nullOnDelete();

                $table->string('usage_type', 80)->index();
                $table->string('reference_type', 100)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->unsignedInteger('quantity')->default(1);

                $table->timestamp('used_at')->useCurrent();
                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['reference_type', 'reference_id'], 'mau_reference_idx');
                $table->index(['user_id', 'used_at']);
            });
        }

        if (!Schema::hasTable('membership_renewals')) {
            Schema::create('membership_renewals', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('membership_id')
                    ->constrained('user_memberships')
                    ->cascadeOnDelete();

                $table->foreignId('old_plan_id')
                    ->nullable()
                    ->constrained('membership_plans')
                    ->nullOnDelete();

                $table->foreignId('new_plan_id')
                    ->nullable()
                    ->constrained('membership_plans')
                    ->nullOnDelete();

                $table->foreignId('order_id')
                    ->nullable()
                    ->constrained('membership_orders')
                    ->nullOnDelete();

                $table->timestamp('renewal_date')->useCurrent()->index();
                $table->timestamp('old_expiry_date')->nullable();
                $table->timestamp('new_expiry_date')->nullable();

                $table->decimal('amount', 12, 2)->default(0);
                $table->string('transaction_id', 150)->nullable();

                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'renewal_date']);
            });
        }

        if (!Schema::hasTable('membership_plan_changes')) {
            Schema::create('membership_plan_changes', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('membership_id')
                    ->constrained('user_memberships')
                    ->cascadeOnDelete();

                $table->foreignId('old_plan_id')
                    ->nullable()
                    ->constrained('membership_plans')
                    ->nullOnDelete();

                $table->foreignId('new_plan_id')
                    ->nullable()
                    ->constrained('membership_plans')
                    ->nullOnDelete();

                $table->foreignId('order_id')
                    ->nullable()
                    ->constrained('membership_orders')
                    ->nullOnDelete();

                $table->string('change_type', 30)->index();
                // upgrade, downgrade

                $table->decimal('prorated_amount', 12, 2)->default(0);
                $table->timestamp('effective_at')->nullable()->index();

                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'change_type']);
            });
        }

        if (!Schema::hasTable('membership_invoices')) {
            Schema::create('membership_invoices', function (Blueprint $table) {
                $table->id();

                $table->foreignId('membership_order_id')
                    ->nullable()
                    ->unique()
                    ->constrained('membership_orders')
                    ->nullOnDelete();

                $table->foreignId('addon_order_id')
                    ->nullable()
                    ->unique()
                    ->constrained('membership_addon_orders')
                    ->nullOnDelete();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->string('invoice_number', 80)->unique();
                $table->timestamp('invoice_date')->useCurrent()->index();

                $table->string('currency', 10)->default('INR');

                $table->decimal('taxable_amount', 12, 2)->default(0);
                $table->decimal('gst_percentage', 5, 2)->default(18);
                $table->decimal('cgst_amount', 12, 2)->default(0);
                $table->decimal('sgst_amount', 12, 2)->default(0);
                $table->decimal('igst_amount', 12, 2)->default(0);
                $table->decimal('gst_amount', 12, 2)->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);

                $table->string('billing_name', 150)->nullable();
                $table->string('billing_email', 150)->nullable();
                $table->string('billing_phone', 30)->nullable();
                $table->string('billing_gst_number', 30)->nullable();
                $table->longText('billing_address')->nullable();
                $table->string('place_of_supply', 100)->nullable();

                $table->string('invoice_pdf_disk', 50)->nullable();
                $table->string('invoice_pdf_path', 500)->nullable();

                $table->string('status', 30)->default('generated')->index();
                // generated, sent, cancelled

                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'invoice_date']);
            });
        }

        if (!Schema::hasTable('membership_notifications')) {
            Schema::create('membership_notifications', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('membership_id')
                    ->nullable()
                    ->constrained('user_memberships')
                    ->nullOnDelete();

                $table->string('title', 200);
                $table->text('message');
                $table->string('notification_type', 80)->index();
                $table->string('channel', 30)->default('database')->index();

                $table->timestamp('read_at')->nullable()->index();
                $table->timestamp('scheduled_at')->nullable()->index();
                $table->timestamp('sent_at')->nullable()->index();

                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'read_at']);
            });
        }

        if (!Schema::hasTable('membership_settings')) {
            Schema::create('membership_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 150)->unique();
                $table->longText('value')->nullable();
                $table->string('value_type', 30)->default('string');
                // string, number, boolean, json
                $table->boolean('is_public')->default(false)->index();
                $table->string('description', 500)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('membership_audit_logs')) {
            Schema::create('membership_audit_logs', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->foreignId('performed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->string('auditable_type', 150)->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();

                $table->string('action', 100)->index();
                $table->longText('old_values')->nullable();
                $table->longText('new_values')->nullable();

                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();

                $table->timestamp('created_at')->useCurrent();

                $table->index(['auditable_type', 'auditable_id'], 'membership_audit_auditable_idx');
                $table->index(['performed_by', 'created_at']);
            });
        }

        if (!Schema::hasTable('membership_teams')) {
            Schema::create('membership_teams', function (Blueprint $table) {
                $table->id();

                $table->foreignId('owner_user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('membership_id')
                    ->nullable()
                    ->constrained('user_memberships')
                    ->nullOnDelete();

                $table->string('name', 150);
                $table->string('slug', 150)->nullable()->unique();

                $table->unsignedInteger('max_members')->nullable();
                $table->string('status', 30)->default('active')->index();
                // active, suspended, cancelled

                $table->longText('metadata')->nullable();
                $table->timestamps();

                $table->index(['owner_user_id', 'status']);
                $table->index('membership_id');
            });
        }

        if (!Schema::hasTable('membership_team_members')) {
            Schema::create('membership_team_members', function (Blueprint $table) {
                $table->id();

                $table->foreignId('team_id')
                    ->constrained('membership_teams')
                    ->cascadeOnDelete();

                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                $table->foreignId('role_id')
                    ->nullable()
                    ->constrained('roles')
                    ->nullOnDelete();

                $table->string('team_role', 50)->default('member');
                // owner, admin, agent, member

                $table->string('status', 30)->default('active')->index();
                // invited, active, suspended, removed

                $table->foreignId('invited_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['team_id', 'user_id']);
                $table->index(['user_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_team_members');
        Schema::dropIfExists('membership_teams');
        Schema::dropIfExists('membership_audit_logs');
        Schema::dropIfExists('membership_settings');
        Schema::dropIfExists('membership_notifications');
        Schema::dropIfExists('membership_invoices');
        Schema::dropIfExists('membership_plan_changes');
        Schema::dropIfExists('membership_renewals');
        Schema::dropIfExists('membership_addon_usages');
        Schema::dropIfExists('membership_lead_unlocks');
        Schema::dropIfExists('membership_credit_transactions');
        Schema::dropIfExists('membership_credit_balances');
        Schema::dropIfExists('membership_coupon_usages');
        Schema::dropIfExists('membership_refunds');
        Schema::dropIfExists('membership_webhook_events');
        Schema::dropIfExists('membership_payments');
        Schema::dropIfExists('membership_addon_orders');
        Schema::dropIfExists('membership_addons');
        Schema::dropIfExists('user_memberships');
        Schema::dropIfExists('membership_orders');
        Schema::dropIfExists('membership_coupons');
        Schema::dropIfExists('membership_plan_role_rules');
        Schema::dropIfExists('membership_plan_features');
        Schema::dropIfExists('membership_plans');
        Schema::dropIfExists('membership_features');
        Schema::dropIfExists('membership_categories');
    }
};