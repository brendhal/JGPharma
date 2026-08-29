import type { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon(props: ImgHTMLAttributes<HTMLImageElement>) {
    const { className, ...rest } = props;

    return (
        <img
            src="/logo.png"
            alt="JG Ramos Drugmart"
            className={`object-contain ${className ?? ''}`}
            {...rest}
        />
    );
}
