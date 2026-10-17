import { mountLoginLighthouse } from './lighthouse/login-scene';

document.addEventListener("DOMContentLoaded", () => {
/* Validação complementar; o envio e a autenticação pertencem ao Laravel. */
(() => {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const form = $('#login-form');
  const email = $('#email');
  const password = $('#password');
  const reveal = $('#show-password');
  const caps = $('#caps-warning');
  const notice = $('#form-notice');
  const submit = $('#submit-button');
  const submitLabel = $('#submit-label');
  let submitting = false;
  form.noValidate = true;
  const errors = {email:$('#email-error'),password:$('#password-error')};
  const messages = {
    email:'Informe um e-mail válido.',
    password:'Digite sua senha para continuar.'
  };
  function setError(name, message='') {
    const field = name === 'email' ? email : password;
    const hint = errors[name];
    hint.textContent = message;
    hint.hidden = !message;
    field.closest('.input-wrap').classList.toggle('is-invalid',!!message);
    field.setAttribute('aria-invalid',message?'true':'false');
  }
  function showNotice(message, error=false) {
    notice.hidden = false;
    notice.textContent = message;
    notice.classList.toggle('is-error',error);
  }
  function resetNotice() { if (!submitting) { notice.hidden=true; notice.textContent=''; }}
  function validate(name) {
    const valid = name === 'email' ? email.validity.valid : password.value.length>0;
    setError(name,valid?'':messages[name]);
    return valid;
  }
  for(const [name,element] of [['email',email],['password',password]]) {
    element.addEventListener('input',() => {if(element.getAttribute('aria-invalid')==='true') validate(name);resetNotice();});
    element.addEventListener('blur',() => {if(element.value.trim())validate(name)});
  }
  reveal.addEventListener('click', () => {
    const show = password.type === 'password';
    password.type = show?'text':'password';
    reveal.textContent = show?'Ocultar':'Mostrar';
    reveal.setAttribute('aria-label',show?'Ocultar senha':'Mostrar senha');
    reveal.setAttribute('aria-pressed',String(show));
    password.focus({preventScroll:true});
  });
  function updateCaps(e) { if(e.getModifierState) caps.hidden = !e.getModifierState('CapsLock'); }
  password.addEventListener('keydown',updateCaps);
  password.addEventListener('keyup',updateCaps);
  password.addEventListener('blur',() => {caps.hidden=true});
  form.addEventListener('submit',(event) => {
    if(submitting) {event.preventDefault();return;}
    const emailValid = validate('email'), passwordValid = validate('password');
    if(!emailValid || !passwordValid){
      event.preventDefault();
      (emailValid?password:email).focus();
      showNotice('Confira os campos destacados antes de continuar.',true);
      return;
    }
    // Native POST preserves CSRF, session redirects and server validation.
    submitting=true;submit.disabled=true;submitLabel.textContent='Verificando…';notice.hidden=true;
  });
  window.addEventListener('pageshow',()=>{
    submitting=false;submit.disabled=false;submitLabel.textContent='Entrar';
  });

  const modal = $('#support-modal');
  const support = $('#open-support');
  const close = $('#close-support');
  const understood = $('#understood');
  function openModal(){ modal.hidden=false;close.focus();document.body.style.overflow='hidden'; }
  function closeModal(){ modal.hidden=true;document.body.style.removeProperty('overflow');support.focus(); }
  support.addEventListener('click',openModal);
  close.addEventListener('click',closeModal);
  understood.addEventListener('click',closeModal);
  modal.addEventListener('click',(e) => {if(e.target===modal)closeModal();});
  document.addEventListener('keydown',(e)=>{
    if(modal.hidden)return;
    if(e.key==='Escape')closeModal();
    if(e.key==='Tab'){
      const stops=[close,understood];
      if(e.shiftKey&&document.activeElement===stops[0]){e.preventDefault();stops[1].focus();}
      if(!e.shiftKey&&document.activeElement===stops[1]){e.preventDefault();stops[0].focus();}
    }
  });

})();

});
  
mountLoginLighthouse();
