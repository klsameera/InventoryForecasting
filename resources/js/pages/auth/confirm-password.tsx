import { Form, Head } from '@inertiajs/react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import FormField from '@/components/form-field';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import Spinner from '@/components/spinner';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <>
            <Head title="Confirm password" />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label="Confirm with passkey"
                loadingLabel="Confirming…"
                separator="Or confirm with password"
            />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <>
                        <FormField
                            id="password"
                            label="Password"
                            error={errors.password}
                            required
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="Your password"
                                autoComplete="current-password"
                                autoFocus
                            />
                        </FormField>

                        <button
                            type="submit"
                            className="btn btn-gradient btn-lg w-100 mt-4"
                            disabled={processing}
                            data-test="confirm-password-button"
                        >
                            {processing && <Spinner size="sm" />}
                            Confirm password
                        </button>
                    </>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Confirm your password',
    description:
        'This is a secure area. Please confirm your password before continuing.',
};
