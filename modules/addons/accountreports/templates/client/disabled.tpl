<div class="accountreports-disabled">
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        {if $message}{$message|escape}{else}{$_ADDONLANG.disabledDefault|default:'This feature is not available on your account.'}{/if}
    </div>
</div>
