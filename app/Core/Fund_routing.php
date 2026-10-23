<?php

namespace App\Core;

/** One branch catalog per controller request. */
trait Fund_routing
{
    private ?\App\Libraries\Fund_cost_center $fund_routing = null;

    protected function fund_router(): \App\Libraries\Fund_cost_center
    {
        return $this->fund_routing ??= new \App\Libraries\Fund_cost_center();
    }
}
