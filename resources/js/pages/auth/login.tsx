import { Form, Head } from '@inertiajs/react';
import Alert from '@/components/alert';
import FormField from '@/components/form-field';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import Spinner from '@/components/spinner';
import TextLink from '@/components/text-link';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Log in" />

            {status && (
                <Alert tone="success" className="mb-3">
                    <p>{status}</p>
                </Alert>
            )}

            <PasskeyVerify />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <>
                        <FormField
                            id="email"
                            label="Email address"
                            error={errors.email}
                            required
                        >
                            <input
                                id="email"
                                name="email"
                                type="email"
                                className="form-control"
                                placeholder="you@company.com"
                                autoComplete="email"
                                required
                                autoFocus
                                tabIndex={1}
                            />
                        </FormField>

                        <FormField
                            id="password"
                            label="Password"
                            error={errors.password}
                            required
                            action={
                                canResetPassword ? (
                                    <TextLink
                                        href={request()}
                                        className="small"
                                        tabIndex={5}
                                    >
                                        Forgot password?
                                    </TextLink>
                                ) : undefined
                            }
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="Your password"
                                autoComplete="current-password"
                                required
                                tabIndex={2}
                            />
                        </FormField>

                        <div className="form-check mt-3">
                            <input
                                className="form-check-input"
                                type="checkbox"
                                id="remember"
                                name="remember"
                                tabIndex={3}
                            />
                            <label
                                className="form-check-label"
                                htmlFor="remember"
                            >
                                Keep me signed in
                            </label>
                        </div>

                        <button
                            type="submit"
                            className="btn btn-gradient btn-lg w-100 mt-4"
                            disabled={processing}
                            tabIndex={4}
                            data-test="login-button"
                        >
                            {processing && <Spinner size="sm" />}
                            Log in
                        </button>
                    </>
                )}
            </Form>

            <p className="app-auth__footer">
                Don&apos;t have an account?{' '}
                <TextLink href={register()} tabIndex={5}>
                    Create one
                </TextLink>
            </p>
        </>
    );
}

Login.layout = {
    title: 'Welcome back',
    description: 'Enter your details to pick up where you left off.',
};
