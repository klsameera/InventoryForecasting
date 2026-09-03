import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import InputError from '@/components/input-error';
import Spinner from '@/components/spinner';

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
};

export default function PasskeyVerify({
    routes,
    label,
    loadingLabel,
    separator,
}: Props = {}) {
    const { verify, isLoading, error, isSupported } = usePasskeyVerify({
        ...(routes && {
            routes: {
                options: routes.options.url,
                submit: routes.submit.url,
            },
        }),
        onSuccess: (response) => {
            router.visit(response.redirect ?? '/dashboard');
        },
    });

    if (!isSupported) {
        return null;
    }

    return (
        <>
            <button
                type="button"
                className="btn btn-surface w-100"
                onClick={verify}
                disabled={isLoading}
            >
                {isLoading ? (
                    <Spinner size="sm" />
                ) : (
                    <KeyRound aria-hidden="true" />
                )}
                {isLoading
                    ? (loadingLabel ?? 'Authenticating…')
                    : (label ?? 'Sign in with a passkey')}
            </button>

            {error && <InputError message={error} className="mt-2" />}

            <div className="app-auth__separator">
                {separator ?? 'Or continue with email'}
            </div>
        </>
    );
}
