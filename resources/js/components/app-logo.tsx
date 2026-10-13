import { cn } from '@/lib/utils';

type AppLogoProps = {
    className?: string;
};

/**
 * Text lockup until a vector (SVG) version of the logo is supplied.
 * The full raster logo lives at /images/brand/logo-full.jpg.
 */
export default function AppLogo({ className }: AppLogoProps) {
    return (
        <span
            className={cn(
                'inline-flex flex-col font-display leading-none font-bold tracking-wide uppercase',
                className,
            )}
        >
            <span className="text-royal-800 dark:text-royal-100">
                Jerry Great
            </span>
            <span className="text-gold-500">Academy</span>
        </span>
    );
}
