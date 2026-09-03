import { Head } from '@inertiajs/react';
import SectionCard from '@/components/section-card';
import ThemeToggle from '@/components/theme-toggle';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance settings" />

            <SectionCard
                title="Theme"
                subtitle="Choose a colour scheme, or follow your operating system."
            >
                <ThemeToggle />

                <p className="app-text-muted small mb-0 mt-3">
                    Your choice is stored on this device and applied before the
                    page paints, so there is no flash when you navigate.
                </p>
            </SectionCard>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [{ title: 'Appearance settings', href: editAppearance() }],
};
