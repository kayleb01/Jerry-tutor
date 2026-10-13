import { cn } from '@/lib/utils';

type AppLogoImageProps = {
    className?: string;
};

/**
 * The full raster logo. Its baked-in light grey background is blended away in
 * light mode and sits on a light panel in dark mode, where the indigo
 * lettering would otherwise disappear.
 */
export default function AppLogoImage({ className }: AppLogoImageProps) {
    return (
        <span
            className={cn(
                'inline-flex rounded-lg dark:bg-[#f2f2f2] dark:px-3 dark:py-2',
                className,
            )}
        >
            <img
                src="/images/brand/logo.jpg"
                alt="Jerry Great Academy"
                width={740}
                height={340}
                className="h-auto w-full mix-blend-multiply dark:mix-blend-normal"
            />
        </span>
    );
}
