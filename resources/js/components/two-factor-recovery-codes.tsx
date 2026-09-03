import { Form } from '@inertiajs/react';
import { Eye, EyeOff, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import Alert from '@/components/alert';
import Spinner from '@/components/spinner';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

type Props = {
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorRecoveryCodes({
    recoveryCodesList,
    fetchRecoveryCodes,
    errors,
}: Props) {
    const [visible, setVisible] = useState(false);
    const codesRef = useRef<HTMLDivElement | null>(null);
    const canRegenerate = recoveryCodesList.length > 0 && visible;

    const toggleVisibility = useCallback(async (): Promise<void> => {
        if (!visible && !recoveryCodesList.length) {
            await fetchRecoveryCodes();
        }

        setVisible((previous) => !previous);
    }, [visible, recoveryCodesList.length, fetchRecoveryCodes]);

    useEffect(() => {
        if (!recoveryCodesList.length) {
            fetchRecoveryCodes();
        }
    }, [recoveryCodesList.length, fetchRecoveryCodes]);

    const ToggleIcon = visible ? EyeOff : Eye;

    return (
        <div className="app-surface-sunken app-rounded-md p-3">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <p className="fw-semibold mb-1">Recovery codes</p>
                    <p className="app-text-muted small mb-0">
                        Each code works once if you lose your authenticator.
                        Store them in a password manager.
                    </p>
                </div>

                <div className="app-form-actions">
                    <button
                        type="button"
                        className="btn btn-surface btn-sm"
                        onClick={toggleVisibility}
                        aria-expanded={visible}
                        aria-controls="recovery-codes"
                    >
                        <ToggleIcon aria-hidden="true" />
                        {visible ? 'Hide' : 'View'} codes
                    </button>

                    {canRegenerate && (
                        <Form
                            {...regenerateRecoveryCodes.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={fetchRecoveryCodes}
                        >
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    className="btn btn-quiet btn-sm"
                                    disabled={processing}
                                >
                                    {processing ? (
                                        <Spinner size="sm" />
                                    ) : (
                                        <RefreshCw aria-hidden="true" />
                                    )}
                                    Regenerate
                                </button>
                            )}
                        </Form>
                    )}
                </div>
            </div>

            {visible && (
                <div id="recovery-codes" className="mt-3">
                    {errors.length > 0 ? (
                        <Alert tone="danger" messages={errors} />
                    ) : (
                        <div
                            ref={codesRef}
                            className="app-surface app-border app-rounded-md p-3 app-text-mono small"
                            role="list"
                            aria-label="Recovery codes"
                        >
                            {recoveryCodesList.length > 0
                                ? recoveryCodesList.map((code) => (
                                      <div key={code} role="listitem">
                                          {code}
                                      </div>
                                  ))
                                : Array.from({ length: 8 }, (_, index) => (
                                      <span
                                          key={index}
                                          className="app-skeleton my-2"
                                          aria-hidden="true"
                                      />
                                  ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
