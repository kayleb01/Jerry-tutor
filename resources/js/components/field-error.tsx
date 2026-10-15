type FieldErrorProps = {
    id: string;
    message?: string;
};

export default function FieldError({ id, message }: FieldErrorProps) {
    if (!message) {
        return null;
    }

    return (
        <p id={id} role="alert" className="text-[0.8125rem] text-danger">
            {message}
        </p>
    );
}
