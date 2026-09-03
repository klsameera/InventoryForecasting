import { Form, Head } from '@inertiajs/react';
import FormField from '@/components/form-field';
import PasswordInput from '@/components/password-input';
import Spinner from '@/components/spinner';
import TextLink from '@/components/text-link';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

export default function Register({ passwordRules }: Props) {
    return (
        <>
            <Head title="Register" />

            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
            >
                {({ processing, errors }) => (
                    <>
                        <FormField
                            id="name"
                            label="Full name"
                            error={errors.name}
                            required
                        >
                            <input
                                id="name"
                                name="name"
                                type="text"
                                className="form-control"
                                placeholder="Jordan Ellis"
                                autoComplete="name"
                                required
                                autoFocus
                                tabIndex={1}
                            />
                        </FormField>

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
                                tabIndex={2}
                            />
                        </FormField>

                        <FormField
                            id="password"
                            label="Password"
                            error={errors.password}
                            required
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="Create a password"
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                                required
                                tabIndex={3}
                            />
                        </FormField>

                        <FormField
                            id="password_confirmation"
                            label="Confirm password"
                            error={errors.password_confirmation}
                            required
                        >
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                placeholder="Repeat your password"
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                                required
                                tabIndex={4}
                            />
                        </FormField>

                        <button
                            type="submit"
                            className="btn btn-gradient btn-lg w-100 mt-4"
                            disabled={processing}
                            tabIndex={5}
                            data-test="register-user-button"
                        >
                            {processing && <Spinner size="sm" />}
                            Create account
                        </button>
                    </>
                )}
            </Form>

            <p className="app-auth__footer">
                Already have an account?{' '}
                <TextLink href={login()} tabIndex={6}>
                    Log in
                </TextLink>
            </p>
        </>
    );
}

Register.layout = {
    title: 'Create your account',
    description: 'A few details and you are ready to start forecasting.',
};
