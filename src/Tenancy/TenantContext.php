<?php

namespace Awesome\Abac\Tenancy;

use Awesome\Abac\Models\Account;

class TenantContext
{
    protected ?Account $currentAccount = null;

    public function setAccount(Account $account)
    {
        $this->currentAccount = $account;
    }

    public function getAccount(): ?Account
    {
        return $this->currentAccount;
    }

    public function getAccountId()
    {
        return $this->currentAccount?->id;
    }

    public function clear()
    {
        $this->currentAccount = null;
    }
}
