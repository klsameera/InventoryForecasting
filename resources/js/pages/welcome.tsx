import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ChartSpline,
    PackageSearch,
    ShieldCheck,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import ThemeToggle from '@/components/theme-toggle';
import { dashboard, login, register } from '@/routes';

const features = [
    {
        icon: ChartSpline,
        title: 'Demand forecasting',
        description:
            'Project demand per SKU from your own sales history, then track how the forecast performed.',
    },
    {
        icon: PackageSearch,
        title: 'Coverage & reorder points',
        description:
            'See days of cover at a glance and get an alert before a line runs dry.',
    },
    {
        icon: ShieldCheck,
        title: 'Secure access',
        description:
            'Two-factor authentication and passkeys ship with the application from day one.',
    },
];

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />

            <div className="app-shell">
                <div className="app-marketing">
                    <header className="app-marketing__nav">
                        <AppLogo />

                        <div className="d-flex align-items-center gap-2">
                            <ThemeToggle variant="compact" />

                            {auth?.user ? (
                                <Link
                                    href={dashboard()}
                                    className="btn btn-gradient btn-sm"
                                >
                                    Go to dashboard
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="btn btn-quiet btn-sm"
                                    >
                                        Log in
                                    </Link>
                                    <Link
                                        href={register()}
                                        className="btn btn-gradient btn-sm"
                                    >
                                        Get started
                                    </Link>
                                </>
                            )}
                        </div>
                    </header>

                    <main className="app-marketing__hero">
                        <span className="app-eyebrow">
                            Inventory intelligence
                        </span>

                        <h1 className="app-marketing__title">
                            Know what to reorder,
                            <br />
                            <span className="app-text-gradient">
                                before the shelf runs empty.
                            </span>
                        </h1>

                        <p className="app-marketing__lead">
                            Turn your sales history into demand forecasts,
                            coverage figures and reorder points your team can
                            act on.
                        </p>

                        {!auth?.user && (
                            <div className="d-flex flex-wrap justify-content-center gap-2 mt-4">
                                <Link
                                    href={register()}
                                    className="btn btn-gradient btn-lg"
                                >
                                    Create an account
                                    <ArrowRight aria-hidden="true" />
                                </Link>
                                <Link
                                    href={login()}
                                    className="btn btn-surface btn-lg"
                                >
                                    Log in
                                </Link>
                            </div>
                        )}

                        <div className="app-grid-cards app-grid-cards--wide mt-5 text-start">
                            {features.map(
                                ({ icon: Icon, title, description }) => (
                                    <article
                                        key={title}
                                        className="app-card app-card--interactive"
                                    >
                                        <div className="app-card__body">
                                            <span className="app-stat__icon mb-3">
                                                <Icon aria-hidden="true" />
                                            </span>
                                            <h2 className="app-card__title">
                                                {title}
                                            </h2>
                                            <p className="app-card__subtitle">
                                                {description}
                                            </p>
                                        </div>
                                    </article>
                                ),
                            )}
                        </div>
                    </main>
                </div>
            </div>
        </>
    );
}
