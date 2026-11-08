<?php
// Shared Manager View State
// Note: Global navigation and workspace headers are now in the primary topbar.
// Secondary subnav and workspace banners have been completely removed.
$tab = $activeTab ?? 'overview';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
$isPetStore = (int)$unitId === 1;
$isVetClinic = (int)$unitId === \App\Models\Units::vetClinicId();
$isGrooming = (int)$unitId === 3;
$isTempInv = !empty($isTempKeyAccess);
