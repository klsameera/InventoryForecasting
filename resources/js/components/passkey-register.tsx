import { usePasskeyRegister } from '@laravel/passkeys/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import FormField from '@/components/form-field';
import Spinner from '@/components/spinner';

type Props = {
    onSuccess: () => void;
};

function suggestPasskeyName(): string {
    const ua = navigator.userAgent;

    const browser = [
        { pattern: /Edg|Edge/, name: 'Edge' },
        { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
        { pattern: /Firefox|FxiOS/, name: 'Firefox' },
        { pattern: /Chrome|CriOS/, name: 'Chrome' },
        { pattern: /Safari/, name: 'Safari' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    const os = [
        { pattern: /iPhone/, name: 'iPhone' },
        { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
        { pattern: /Android/, name: 'Android' },
        { pattern: /Mac/, name: 'Mac' },
        { pattern: /Windows/, name: 'Windows' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    return [browser, os].filter(Boolean).join(' on ') || '';
}

export default function PasskeyRegistration({ onSuccess }: Props) {
    const [name, setName] = useState(suggestPasskeyName);
    const [showForm, setShowForm] = useState(false);

    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        onSuccess: () => {
            setName('');
            setShowForm(false);
            onSuccess();
        },
    });

    const handleSubmit = async (event: FormEvent): Promise<void> => {
        event.preventDefault();

        if (!name.trim()) {
            return;
        }

        await register(name);
    };

    if (!isSupported) {
        return (
            <p className="app-text-muted small mb-0">
                Passkeys are not supported in this browser.
            </p>
        );
    }

    if (!showForm) {
        return (
            <button
                type="button"
                className="btn btn-surface btn-sm"
                onClick={() => setShowForm(true)}
            >
                <Plus aria-hidden="true" />
                Add passkey
            </button>
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="app-surface-sunken app-rounded-md p-3"
        >
            <FormField
                id="passkey-name"
                label="Passkey name"
                hint="A name helps you identify this passkey later."
                error={error ?? undefined}
            >
                <input
                    id="passkey-name"
                    type="text"
                    className="form-control"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder="e.g. MacBook Pro, iPhone"
                    autoFocus
                />
            </FormField>

            <div className="app-form-actions mt-3">
                <button
                    type="submit"
                    className="btn btn-gradient btn-sm"
                    disabled={isLoading || !name.trim()}
                >
                    {isLoading && <Spinner size="sm" />}
                    {isLoading ? 'Registering…' : 'Register passkey'}
                </button>
                <button
                    type="button"
                    className="btn btn-quiet btn-sm"
                    onClick={() => setShowForm(false)}
                >
                    Cancel
                </button>
            </div>
        </form>
    );
}
