<?php

declare(strict_types=1);

namespace App\Services\Smoke;

use App\Services\Smoke\Probes\AcmeChallengeProbe;
use App\Services\Smoke\Probes\AdminGateProbe;
use App\Services\Smoke\Probes\CanonicalProbe;
use App\Services\Smoke\Probes\CertificateProbe;
use App\Services\Smoke\Probes\CompiledAssetsProbe;
use App\Services\Smoke\Probes\E2eBackdoorProbe;
use App\Services\Smoke\Probes\ErrorPagesProbe;
use App\Services\Smoke\Probes\ExposureProbe;
use App\Services\Smoke\Probes\GeoProbe;
use App\Services\Smoke\Probes\HealthProbe;
use App\Services\Smoke\Probes\HttpsRedirectProbe;
use App\Services\Smoke\Probes\LocaleRedirectProbe;
use App\Services\Smoke\Probes\PublicPagesProbe;
use App\Services\Smoke\Probes\RealContentProbe;
use App\Services\Smoke\Probes\ReleaseProbe;
use App\Services\Smoke\Probes\ResponseTimeProbe;
use App\Services\Smoke\Probes\SessionProbe;
use App\Services\Smoke\Probes\TelescopeProbe;
use App\Services\Smoke\Probes\TranslationsProbe;

/**
 * Everything `deploy:smoke` asks of a deployed environment, in the order it asks it (LUMN-49).
 *
 * The order is part of the contract: the release comes first, because if the switch to a new release
 * failed, every probe below is testing the old one — and passing.
 *
 * A probe class that is not listed here never runs. SmokeCatalogueTest fails on that: a probe fully
 * tested in isolation and never executed is exactly the kind of silence this suite exists to end.
 */
final class SmokeCatalogue
{
    /** @var list<class-string<SmokeProbe>> */
    public const PROBES = [
        ReleaseProbe::class,
        HealthProbe::class,
        PublicPagesProbe::class,
        RealContentProbe::class,
        TranslationsProbe::class,
        CanonicalProbe::class,
        CompiledAssetsProbe::class,
        E2eBackdoorProbe::class,
        TelescopeProbe::class,
        ExposureProbe::class,
        ErrorPagesProbe::class,
        AdminGateProbe::class,
        SessionProbe::class,
        LocaleRedirectProbe::class,
        HttpsRedirectProbe::class,
        CertificateProbe::class,
        AcmeChallengeProbe::class,
        GeoProbe::class,
        ResponseTimeProbe::class,
    ];
}
