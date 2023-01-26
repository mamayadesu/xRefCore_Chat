class Typing {
    constructor() {
        this.typingUsers = {};
        this.el = document.getElementById("typing");
        this.message = document.getElementById("message");
        this.lastType = 0;

        this.message.onkeydown = function(e) {
            this.imTyping();
        }.bind(this);
    }

    setTypingUser(username) {
        if (typeof this.typingUsers[username] != "undefined")
        {
            clearTimeout(this.typingUsers[username]);
        }

        this.typingUsers[username] = setTimeout(function() {
            this.typingUsers[username] = undefined;
            this.refresh();
        }.bind(this), 4000);
        this.refresh();
    }

    now() {
        return Math.round(new Date().getTime() / 1000);
    }

    unsetTypingUser(username) {
        if (typeof this.typingUsers[username] != "undefined")
        {
            clearTimeout(this.typingUsers[username]);
            this.typingUsers[username] = undefined;
        }
        this.refresh();
    }

    getTypingUsers() {
        var result = [];
        for (var username in this.typingUsers)
        {
            if (typeof this.typingUsers[username] != "undefined")
            {
                result.push(username);
            }
        }

        return result;
    }

    getCount() {
        var k = 0;
        for (var username in this.typingUsers)
        {
            if (typeof this.typingUsers[username] != "undefined")
            {
                k++;
            }
        }

        return k;
    }

    show(text) {
        this.el.innerHTML = text;
        this.el.style.color = "black";
    }

    hide() {
        this.el.innerHTML = "_";
        this.el.style.color = "white";
    }

    refresh() {
        var users = this.getTypingUsers();
        var count = this.getCount();
        switch (count) {
            case 0:
                this.hide();
                break;

            case 1:
                this.show(users[0] + " пишет...");
                break;

            default:
                var usersText = "";
                for (var i = 0; i < count; i++) {
                    if (i == count - 1)
                    {
                        usersText += " и " + users[i];
                    } else if (i == 0) {
                        usersText += users[i];
                    } else {
                        usersText += ", " + users[i];
                    }
                }
                usersText += " пишут...";
                this.show(usersText);
                break;
        }
    }

    imTyping() {
        if ((this.now() - this.lastType) < 3)
        {
            return;
        }
        this.lastType = this.now();
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "/typing", true);
        xhr.send();
    }
}