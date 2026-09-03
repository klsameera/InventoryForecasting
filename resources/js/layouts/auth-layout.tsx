import { Link } from '@inertiajs/react';
import { ChartSpline, KeyRound, ShieldCheck } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const highlights = [
    {
        icon: ChartSpline,
        title: 'Forecasts you can act on',
        description:
            'Demand signals, coverage and reorder points in a single view.',
    },
    {
        icon: ShieldCheck,
        title: 'Secure by default',
        description:
            'Two-factor authentication and passkeys are built in from day one.',
    },
    {
        icon: KeyRound,
        title: 'Sign in without a password',
        description: 'Passkeys let your team in with a tap or a glance.',
    },
];

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: AuthLayoutProps) {
    useFlashToast();

    return (
        <div className="app-auth">
            <div className="app-auth__panel">
                <div className="app-auth__card">
                    <Link href={home()} className="app-auth__brand">
                        <AppLogo />
                    </Link>

                    <h1 className="app-auth__heading">{title}</h1>
                    {description && (
                        <p className="app-auth__description">{description}</p>
                    )}

                    {children}
                </div>
            </div>

            <aside className="app-auth__aside">
                <div className="app-auth__aside-inner">
                    <span className="app-eyebrow text-white-50">
                        Inventory Forecasting
                    </span>
                    <p className="app-auth__quote mt-3">
                        Know what to reorder, how much, and when — before the
                        shelf runs empty.
                    </p>
                </div>

                <div className="app-auth__aside-inner">
                    {highlights.map(
                        ({ icon: Icon, title: name, description: copy }) => (
                            <div key={name} className="app-auth__feature">
                                <Icon aria-hidden="true" />
                                <div>
                                    <p className="fw-semibold mb-1">{name}</p>
                                    <p className="mb-0 text-white-50 small">
                                        {copy}
                                    </p>
                                </div>
                            </div>
                        ),
                    )}
                </div>
            </aside>
        </div>
    );
}
