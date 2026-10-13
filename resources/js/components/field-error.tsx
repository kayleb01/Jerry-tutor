type FieldErrorProps = {
    id: string;
    message?: string;
};

export default function FieldError({ id, message }: FieldErrorProps) {
    if (!message) {
        return null;
    }

    return (
        <p id={id} className="text-sm text-danger">
            {message}
        </p>
    );
}
