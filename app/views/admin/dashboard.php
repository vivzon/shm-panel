<?php
// File: app/views/admin/dashboard.php
?>
<div class="container-fluid">
    <h3 class="mb-4">Admin Dashboard</h3>
    <div class="row">
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5>Total Servers</h5>
                    <p class="display-4"><?= (int)$serverCount ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5>Resellers</h5>
                    <p class="display-4"><?= (int)$totalResellers ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5>Clients</h5>
                    <p class="display-4"><?= (int)$totalClients ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5>Active Accounts</h5>
                    <p class="display-4"><?= (int)$activeAccounts ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
