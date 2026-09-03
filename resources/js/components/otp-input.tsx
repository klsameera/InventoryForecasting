import { useRef } from 'react';
import type { ClipboardEvent, KeyboardEvent } from 'react';

type Props = {
    name: string;
    value: string;
    onChange: (value: string) => void;
    length?: number;
    disabled?: boolean;
    autoFocus?: boolean;
};

/**
 * Digit-per-box one-time-code field. A single hidden input carries the joined
 * value so it posts like any other form control.
 */
export default function OtpInput({
    name,
    value,
    onChange,
    length = 6,
    disabled = false,
    autoFocus = false,
}: Props) {
    const inputs = useRef<Array<HTMLInputElement | null>>([]);

    const focusAt = (index: number): void => {
        inputs.current[Math.min(Math.max(index, 0), length - 1)]?.focus();
    };

    const setDigit = (index: number, digit: string): void => {
        const next = value.padEnd(length, ' ').split('');
        next[index] = digit || ' ';

        onChange(next.join('').replace(/\s+$/u, '').trimEnd());
    };

    const handleChange = (index: number, raw: string): void => {
        const digits = raw.replace(/\D/gu, '');

        if (!digits) {
            setDigit(index, '');

            return;
        }

        if (digits.length > 1) {
            onChange(
                (value.slice(0, index) + digits)
                    .replace(/\D/gu, '')
                    .slice(0, length),
            );
            focusAt(index + digits.length);

            return;
        }

        setDigit(index, digits);
        focusAt(index + 1);
    };

    const handleKeyDown = (
        index: number,
        event: KeyboardEvent<HTMLInputElement>,
    ): void => {
        if (event.key === 'Backspace' && !value[index]) {
            event.preventDefault();
            setDigit(index - 1, '');
            focusAt(index - 1);

            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            focusAt(index - 1);

            return;
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            focusAt(index + 1);
        }
    };

    const handlePaste = (event: ClipboardEvent<HTMLInputElement>): void => {
        event.preventDefault();

        const digits = event.clipboardData
            .getData('text')
            .replace(/\D/gu, '')
            .slice(0, length);

        onChange(digits);
        focusAt(digits.length);
    };

    return (
        <div className="app-otp">
            <input type="hidden" name={name} value={value} />

            {Array.from({ length }, (_, index) => (
                <input
                    key={index}
                    ref={(element) => {
                        inputs.current[index] = element;
                    }}
                    className="app-otp__slot"
                    type="text"
                    inputMode="numeric"
                    autoComplete={index === 0 ? 'one-time-code' : 'off'}
                    maxLength={1}
                    value={value[index] ?? ''}
                    disabled={disabled}
                    autoFocus={autoFocus && index === 0}
                    aria-label={`Digit ${index + 1} of ${length}`}
                    onChange={(event) =>
                        handleChange(index, event.target.value)
                    }
                    onKeyDown={(event) => handleKeyDown(index, event)}
                    onPaste={handlePaste}
                    onFocus={(event) => event.target.select()}
                />
            ))}
        </div>
    );
}
