type FieldErrorProps = {
    id: string;
    message?: string;
};

export default function FieldError({ id, message }: FieldErrorProps) {
    if (!message) {
        return null;
    }

    return (
        <p id={id} role="alert" className="text-[13px] text-danger">
            {message}
        </p>
    );
}
