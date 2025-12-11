<?php

namespace Awesome\Abac\Tenancy;

use Awesome\Abac\Tenancy\TenantContext;
use Awesome\Abac\Tenancy\TenantScope;
use Illuminate\Support\Facades\App;

trait UsesTenant
{
    public static function bootUsesTenant()
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = App::make(TenantContext::class);
            $accountId = $context->getAccountId();

            if (!$model->account_id && $accountId) {
                $model->account_id = $accountId;
            }
        });
    }

    public function account()
    {
        return $this->belongsTo(config('awesome-abac.models.account'), 'account_id');
    }

    public function scopeWithoutTenant($query)
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
