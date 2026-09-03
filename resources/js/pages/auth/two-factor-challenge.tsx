import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import OtpInput from '@/components/otp-input';
import Spinner from '@/components/spinner';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);
    const [code, setCode] = useState('');

    const copy = useMemo(() => {
        if (useRecoveryCode) {
            return {
                title: 'Enter a recovery code',
                description:
                    'Confirm access to your account with one of your emergency recovery codes.',
                toggleText: 'use an authentication code instead',
            };
        }

        return {
            title: 'Two-factor authentication',
            description:
                'Enter the 6-digit code from your authenticator application.',
            toggleText: 'use a recovery code instead',
        };
    }, [useRecoveryCode]);

    setLayoutProps({
        title: copy.title,
        description: copy.description,
    });

    return (
        <>
            <Head title="Two-factor authentication" />

            <Form
                {...store.form()}
                resetOnError
                resetOnSuccess={!useRecoveryCode}
            >
                {({ errors, processing, clearErrors }) => (
                    <>
                        {useRecoveryCode ? (
                            <FormField
                                id="recovery_code"
                                label="Recovery code"
                                error={errors.recovery_code}
                                required
                            >
                                <input
                                    id="recovery_code"
                                    name="recovery_code"
                                    type="text"
                                    className="form-control app-text-mono"
                                    placeholder="xxxxxxxx-xxxxxxxx"
                                    autoComplete="one-time-code"
                                    autoFocus
                                    required
                                />
                            </FormField>
                        ) : (
                            <div className="d-flex flex-column gap-2">
                                <OtpInput
                                    name="code"
                                    value={code}
                                    onChange={setCode}
                                    length={OTP_MAX_LENGTH}
                                    disabled={processing}
                                    autoFocus
                                />
                                <InputError
                                    message={errors.code}
                                    className="justify-content-center"
                                />
                            </div>
                        )}

                        <button
                            type="submit"
                            className="btn btn-gradient btn-lg w-100 mt-4"
                            disabled={processing}
                        >
                            {processing && <Spinner size="sm" />}
                            Continue
                        </button>

                        <p className="app-auth__footer">
                            Having trouble?{' '}
                            <button
                                type="button"
                                className="btn btn-link p-0 align-baseline app-text-link"
                                onClick={() => {
                                    setUseRecoveryCode((value) => !value);
                                    clearErrors();
                                    setCode('');
                                }}
                            >
                                {copy.toggleText}
                            </button>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}
