import { forwardRef, useId, useImperativeHandle, useLayoutEffect, useRef } from 'react';

// Credenciais ficam somente nos inputs, nunca nos valores da proposta ou no histórico.
export default forwardRef(function Reautenticacao({ tipo, bloqueado }, ref) {
    const id = useId();
    const identificador = useRef(null);
    const segredo = useRef(null);
    const crmv = tipo === 'crmv';
    const pin = tipo === 'estorno';
    const limpar = () => {
        if (identificador.current) identificador.current.value = '';
        if (segredo.current) segredo.current.value = '';
    };
    useLayoutEffect(() => {
        const campos = [identificador.current, segredo.current];
        return () => campos.forEach((campo) => { if (campo) campo.value = ''; });
    }, []);
    useImperativeHandle(ref, () => ({
        validar: () => identificador.current.reportValidity() && segredo.current.reportValidity(),
        consumir: () => {
            const credenciais = { email_admin: identificador.current.value, senha_admin: segredo.current.value };
            limpar();
            return credenciais;
        },
        limpar,
    }));
    return <fieldset className="card-autorizacao" disabled={bloqueado} style={{ minWidth: 0 }}>
        <legend>{crmv ? 'Confirme com o seu CRMV e a sua senha' : pin ? 'Autorização do supervisor do setor ou do dono' : 'Autorização de um administrador'}</legend>
        <p className="autorizacao-info">{pin ? 'O PIN' : 'A senha'} só será enviado junto com a confirmação e será apagado após a tentativa.</p>
        <div className="campo">
            <label htmlFor={`${id}-identificador`}>{crmv ? 'Seu CRMV (como está no perfil)' : pin ? 'E-mail de quem autoriza' : 'E-mail do administrador'}</label>
            <input ref={identificador} id={`${id}-identificador`} type={crmv ? 'text' : 'email'} required maxLength={crmv ? 40 : 255} autoComplete="off" />
        </div>
        <div className="campo">
            <label htmlFor={`${id}-segredo`}>{crmv ? 'Sua senha' : pin ? 'PIN de quem autoriza' : 'Senha do administrador'}</label>
            <input ref={segredo} id={`${id}-segredo`} type="password" required maxLength={255} autoComplete="new-password" />
        </div>
    </fieldset>;
});
