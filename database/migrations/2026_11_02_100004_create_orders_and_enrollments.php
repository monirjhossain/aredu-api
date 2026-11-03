<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('order_number')->unique();
            $table->string('status', 20)->default('pending');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('currency', 3)->default('BDT');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'course_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 20);
            $table->string('status', 20)->default('initiated');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('BDT');
            $table->string('gateway_transaction_id')->nullable()->unique();
            $table->string('gateway_reference')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('enrolled_at');
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type', 20);
            $table->decimal('value', 10, 2);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['coupon_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
